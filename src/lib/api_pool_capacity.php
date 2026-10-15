<?php
require_once __DIR__.'/api_site_address_policy.php';

/** Capacity is a read-only union of globally exclusive ranges, not an address reservation. */
final class ApiPoolCapacity
{
    private string $dataPath; private string $cachePath; private string $jobsPath;
    private array $assigned=[]; private array $retained=[]; private int $invalid=0;
    private array $knownDevices=[]; private array $activeDevice=[];
    private float $deadline=0; private int $readRows=0; private int $readBytes=0;
    public function __construct(string $dataPath='data/data.db',string $cachePath='data/cache.db',string $jobsPath='data/jobs.db')
    { $this->dataPath=$dataPath; $this->cachePath=$cachePath; $this->jobsPath=$jobsPath; }

    public function read(): array
    {
        $report=['available'=>false,'checked_at'=>date('c'),'items'=>[],'invalid_records'=>0,'reason'=>'capacity_unavailable'];
        try {
            $this->deadline=microtime(true)+6; $this->readRows=0; $this->readBytes=0;
            if (!extension_loaded('gmp')) throw new RuntimeException('gmp_unavailable');
            [$config,$devices]=ApiSiteAddressPolicy::stored($this->dataPath);
            if (!$devices || !$config) throw new RuntimeException('configuration_unavailable');
            $this->knownDevices=$devices;
            $this->load();
            $specs=[];
            foreach ($devices as $id=>$device) foreach ([false,true] as $v6) {
                $pool=$device[$v6?'pool6':'pool']??null;
                if (is_string($pool) && trim($pool)!=='') $specs[]=['device_id'=>(int)$id,'site'=>$device['name']??(string)$id,'family'=>$v6?'IPv6':'IPv4',
                    'scope'=>'router','pool'=>$pool,'unit_prefix'=>$v6?$this->prefixLength($device['pfxLength']??64):32];
            }
            $routerPools=filter_var($config['router_ppp_pool']??true,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            if ($routerPools===null) throw new RuntimeException('configuration_unavailable');
            if ($routerPools===false && is_string($config['ppp_pool']??null))
                foreach ([false,true] as $v6) $specs[]=['device_id'=>null,'site'=>null,'family'=>$v6?'IPv6':'IPv4','scope'=>'global_ppp','pool'=>$config['ppp_pool'],'unit_prefix'=>$v6?64:32];
            foreach ($specs as $spec) {
                $this->budget();
                $v6=$spec['family']==='IPv6'; $bits=$v6?128:32; $unit=$spec['unit_prefix'];
                try {
                    if ($unit<1 || $unit>$bits) throw new RuntimeException('invalid_pool');
                    $pools=$this->pools($spec['pool'],$v6,$unit); if ($pools===null) continue;
                    $assigned=$this->units($this->assigned[$bits]??[],$unit,$bits);
                    $retained=$this->units($this->retained[$bits]??[],$unit,$bits);
                    $excluded=$this->units($this->exclusions($config['excl_addr']??null,$v6),$unit,$bits);
                    $total=self::measure($pools); $a=self::measure(self::intersect($pools,$assigned));
                    $held=self::measure(self::intersect($pools,self::merge(array_merge($assigned,$retained))));
                    $occupied=self::measure(self::intersect($pools,self::merge(array_merge($assigned,$retained,$excluded))));
                    $free=gmp_sub($total,$occupied); $percent=gmp_cmp($total,0)>0?(int)gmp_strval(gmp_div_q(gmp_mul($free,10000),$total))/100:0;
                    $report['items'][]=$spec+['available'=>true,'total'=>gmp_strval($total),'assigned'=>gmp_strval($a),
                        'retained'=>gmp_strval(gmp_sub($held,$a)),'excluded'=>gmp_strval(gmp_sub($occupied,$held)),
                        'free'=>gmp_strval($free),'free_percent'=>$percent,'warning'=>gmp_cmp($free,0)===0?'exhausted':($percent<=10||gmp_cmp($free,5)<=0?'low':null),
                        'shared_pool'=>false,'reason'=>null];
                } catch (Throwable $ignored) { $report['items'][]=$spec+['available'=>false,'reason'=>'invalid_pool','warning'=>'unknown']; }
            }
            foreach ($report['items'] as $i=>&$item) foreach ($report['items'] as $j=>$peer) {
                $this->budget();
                if ($i===$j || $item['family']!==$peer['family']) continue;
                try { if ($this->overlappingPools($item['pool'],$peer['pool'],$item['family']==='IPv6')) $item['shared_pool']=true; }
                catch (Throwable $ignored) {}
            }
            unset($item); $report['available']=true; $report['reason']=null; $report['invalid_records']=$this->invalid;
        } catch (Throwable $ignored) { $report['invalid_records']=$this->invalid; }
        return $report;
    }

    private function load(): void
    {
        $this->assigned=[]; $this->retained=[]; $this->invalid=0; $this->activeDevice=[]; $active=[]; $blocked=[];
        $legacy=dirname($this->jobsPath).DIRECTORY_SEPARATOR.'queue.json';
        if (is_link($legacy) || file_exists($legacy) && (!is_file($legacy) || filesize($legacy)>1048576)) throw new RuntimeException('ownership_unavailable');
        if (is_file($legacy)) { $raw=file_get_contents($legacy); if ($raw===false || trim($raw)!=='' && json_decode($raw,true,32,JSON_THROW_ON_ERROR)!==[]) throw new RuntimeException('ownership_unavailable'); }
        $jobs=$this->db($this->jobsPath,false);
        if ($jobs!==null) {
            try { foreach ($this->rows($jobs,'service_tombstones','service_id','',false) as $row) {
                $sid=$this->positive($row['service_id']); $blocked[$sid]=true;
            } } catch (Throwable $error) { $jobs->close(); throw $error; }
        }
        $cache=$this->db($this->cachePath,true);
        if ($cache!==null) try {
            foreach ($this->rows($cache,'services','id,status,device') as $row) {
                $sid=$this->positive($row['id']);
                if (!is_int($row['status']) || $row['status']<0 || $row['status']>8) throw new RuntimeException('ownership_unavailable');
                if ((int)$row['device']>0 && isset($this->knownDevices[(int)$row['device']]) && in_array($row['status'],[1,3,4,7],true) && !isset($blocked[$sid])) {
                    $active[$sid]=true; $this->activeDevice[$sid]=(int)$row['device'];
                }
            }
            foreach ($this->rows($cache,'network','id,address,address6') as $row) if (isset($active[$this->positive($row['id'])])) $this->addresses($row,false,true);
        } catch (Throwable $error) { if ($jobs!==null) $jobs->close(); throw $error; }
        finally { $cache->close(); }
        $data=$this->db($this->dataPath,true);
        try { foreach ($this->rows($data,'network','id,address,address6') as $row) $this->addresses($row,!isset($active[$this->positive($row['id'])])); }
        catch (Throwable $error) { if ($jobs!==null) $jobs->close(); throw $error; }
        finally { $data->close(); }
        if ($jobs!==null) try {
            foreach ($this->rows($jobs,'jobs','status') as $row) if (!in_array($row['status'],['pending','running','cancelled','superseded','succeeded','cleaned'],true))
                throw new RuntimeException('ownership_unavailable');
            foreach ($this->rows($jobs,'service_versions','service_id,applied_payload') as $row) {
                if ($row['applied_payload']===null) continue;
                $snapshot=$this->payload($row['applied_payload'],$this->positive($row['service_id'])); $this->snapshot($snapshot,false);
            }
            foreach ($this->rows($jobs,'jobs','service_id,status,payload,prior_cleared,cleanup_done'," WHERE status IN ('pending','running','cancelled','superseded')") as $row) {
                $snapshot=$this->payload($row['payload'],$this->positive($row['service_id']));
                if (!in_array($row['prior_cleared'],[0,1],true) || !in_array($row['cleanup_done'],[0,1],true)) throw new RuntimeException('ownership_unavailable');
                if (array_key_exists('_commands',$snapshot)) $this->snapshot($snapshot,true);
                if (!$row['prior_cleared'] && isset($snapshot['_prior_network'])) $this->historicalNetwork($snapshot['_prior_network']);
                if (!$row['cleanup_done'] && isset($snapshot['_cleanup'])) $this->snapshot($snapshot['_cleanup'],true);
            }
            foreach ($this->rows($jobs,'cancellation_cleanups','service_id,payload','',false) as $row)
                $this->snapshot($this->payload($row['payload'],$this->positive($row['service_id'])),true,true);
            foreach ($this->rows($jobs,'retained_resources','service_id,slot,payload','',false) as $row) {
                $snapshot=$this->payload($row['payload'],$this->positive($row['service_id']));
                if (!is_string($row['slot'])) throw new RuntimeException('ownership_unavailable');
                if ($row['slot']!=='source') { $this->snapshot($snapshot,true,true); continue; }
                if (array_key_exists('_commands',$snapshot)) $this->snapshot($snapshot,true);
                if (isset($snapshot['_prior_network'])) $this->historicalNetwork($snapshot['_prior_network']);
                if (isset($snapshot['_cleanup'])) $this->snapshot($snapshot['_cleanup'],true);
            }
        } finally { $jobs->close(); }
    }
    private function snapshot(array $snapshot,bool $retained,bool $includePrior=false): void
    {
        $this->budget();
        $this->addresses($snapshot,$retained);
        if (array_key_exists('network',$snapshot)) { if (!is_array($snapshot['network'])) throw new RuntimeException('ownership_unavailable'); $this->addresses($snapshot['network'],$retained); }
        if ($includePrior && isset($snapshot['_prior_network'])) $this->historicalNetwork($snapshot['_prior_network']);
        if (array_key_exists('_previous_addresses',$snapshot) && !is_array($snapshot['_previous_addresses'])) throw new RuntimeException('ownership_unavailable');
        foreach ($snapshot['_previous_addresses']??[] as $address) $this->add($address,true);
        if (array_key_exists('_commands',$snapshot) && !is_array($snapshot['_commands'])) throw new RuntimeException('ownership_unavailable');
        foreach ($snapshot['_commands']??[] as $command) {
            if (!is_array($command) || !is_string($command['path']??null)) throw new RuntimeException('ownership_unavailable');
            $path=trim($command['path']??'','/');
            $field=$path==='ppp/secret'?'remote-address':(in_array($path,['ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding'],true)?'address':null);
            if ($field!==null && isset($command[$field])) $this->add($command[$field],$retained);
            if ($path==='ppp/secret' && isset($command['remote-ipv6-prefix'])) $this->add($command['remote-ipv6-prefix'],$retained);
            if ($path==='queue/simple' && ($command['comment']??'')!=='do not delete' && !preg_match('/-parent$/D',$command['name']??'') && isset($command['target']))
                foreach (explode(',',$command['target']) as $address) $this->add($address,$retained);
        }
    }
    private function historicalNetwork($value): void
    { if (!is_array($value)) throw new RuntimeException('ownership_unavailable'); $this->addresses($value,true); }
    private function payload($raw,int $sid): array
    {
        if (!is_string($raw) || strlen($raw)>2097152) throw new RuntimeException('ownership_unavailable');
        $snapshot=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || $this->positive($snapshot['id']??null)!==$sid) throw new RuntimeException('ownership_unavailable'); return $snapshot;
    }
    private function addresses(array $row,bool $retained,bool $cache=false): void
    {
        foreach (['address','address6'] as $field) if (isset($row[$field]) && $row[$field]!=='') {
            $value=$row[$field];
            if ($cache && $field==='address6' && is_string($value) && strpos($value,'/')===false) {
                $id=(int)$row['id']; $device=$this->activeDevice[$id]??null; if ($device===null) throw new RuntimeException('ownership_unavailable');
                $length=$this->prefixLength($this->knownDevices[$device]['pfxLength']??64); if (!$length) throw new RuntimeException('ownership_unavailable'); $value.='/'.$length;
            }
            $this->add($value,$retained);
        }
    }
    private function add($value,bool $retained): void
    {
        try { [$bits,$range]=self::range($value); if ($retained) $this->retained[$bits][]=$range; else $this->assigned[$bits][]=$range; }
        catch (Throwable $ignored) { $this->invalid++; }
    }
    private function pools(string $raw,bool $v6,int $unit): ?array
    {
        $bits=$v6?128:32; $ranges=[]; $found=false;
        foreach (preg_split('/\s*,\s*/',trim($raw)) as $prefix) {
            [$family,$range,$length]=self::range($prefix);
            if ($family!==$bits) continue;
            $found=true;
            if (strpos($prefix,'/')===false || $length<1 || ($v6 && $unit<$length)) throw new RuntimeException('invalid_pool');
            if (!$v6) { $range[0]=gmp_add($range[0],1); $range[1]=gmp_sub($range[1],1); }
            if (gmp_cmp($range[0],$range[1])<=0) $ranges[]=$range;
        }
        return $found?$this->units($ranges,$unit,$bits):null;
    }
    private function overlappingPools(string $first,string $second,bool $v6): bool
    {
        $read=static function(string $raw) use($v6):array {
            $ranges=[]; foreach (preg_split('/\s*,\s*/',trim($raw)) as $prefix) { [$bits,$range]=self::range($prefix); if (($bits===128)===$v6) $ranges[]=$range; } return $ranges;
        };
        return self::intersect($read($first),$read($second))!==[];
    }
    private function prefixLength($value): int
    { if ($value===null || $value==='') return 64; $value=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>128]]); return $value===false?0:$value; }
    private function positive($value): int
    { if (!is_int($value) && !is_string($value)) throw new RuntimeException('ownership_unavailable'); $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if ($id===false) throw new RuntimeException('ownership_unavailable'); return $id; }
    private function exclusions($raw,bool $v6): array
    {
        if ($raw===null || $raw==='') return [];
        if (!is_string($raw)) throw new RuntimeException('invalid_exclusions'); $ranges=[];
        foreach (preg_split('/\s*,\s*/',$raw) as $value) {
            $parts=preg_split('/\s*-\s*/',$value); if (count($parts)>2) throw new RuntimeException('invalid_exclusions');
            [$bits,$first]=self::range($parts[0]); if (($bits===128)!==$v6) continue;
            if (count($parts)===2) { [$lastBits,$last]=self::range($parts[1]); if ($bits!==$lastBits) throw new RuntimeException('invalid_exclusions'); $first[1]=$last[1]; }
            if (gmp_cmp($first[0],$first[1])>0) throw new RuntimeException('invalid_exclusions'); $ranges[]=$first;
        }
        return $ranges;
    }
    private function units(array $ranges,int $unit,int $bits): array
    { $step=gmp_pow(2,$bits-$unit); $out=[]; foreach ($ranges as $r) $out[]=[gmp_div_q($r[0],$step),gmp_div_q($r[1],$step)]; return self::merge($out); }
    private static function range($value): array
    {
        if (!is_string($value) || strlen($value)>128) throw new RuntimeException('invalid_address');
        $parts=explode('/',trim($value)); $packed=@inet_pton($parts[0]); if ($packed===false || count($parts)>2) throw new RuntimeException('invalid_address');
        $bits=strlen($packed)*8; $length=isset($parts[1])&&ctype_digit($parts[1])?(int)$parts[1]:$bits;
        if ((isset($parts[1])&&!ctype_digit($parts[1])) || $length<0 || $length>$bits) throw new RuntimeException('invalid_address');
        $size=gmp_pow(2,$bits-$length); $start=gmp_mul(gmp_div_q(gmp_init(bin2hex($packed),16),$size),$size);
        return [$bits,[$start,gmp_sub(gmp_add($start,$size),1)],$length];
    }
    private static function merge(array $ranges): array
    {
        usort($ranges,static fn($a,$b)=>gmp_cmp($a[0],$b[0])); $out=[];
        foreach ($ranges as $r) { $last=count($out)-1; if ($last>=0 && gmp_cmp($r[0],gmp_add($out[$last][1],1))<=0) { if (gmp_cmp($r[1],$out[$last][1])>0) $out[$last][1]=$r[1]; } else $out[]=$r; }
        return $out;
    }
    private static function intersect(array $a,array $b): array
    {
        $a=self::merge($a); $b=self::merge($b); $out=[]; $i=0; $j=0;
        while ($i<count($a)&&$j<count($b)) { $start=gmp_cmp($a[$i][0],$b[$j][0])>0?$a[$i][0]:$b[$j][0]; $end=gmp_cmp($a[$i][1],$b[$j][1])<0?$a[$i][1]:$b[$j][1];
            if (gmp_cmp($start,$end)<=0) $out[]=[$start,$end]; if (gmp_cmp($a[$i][1],$b[$j][1])<0) $i++; else $j++; }
        return self::merge($out);
    }
    private static function measure(array $ranges)
    { $count=gmp_init(0); foreach (self::merge($ranges) as $r) $count=gmp_add($count,gmp_add(gmp_sub($r[1],$r[0]),1)); return $count; }
    private function db(string $path,bool $required): ?SQLite3
    {
        if (is_link($path)) throw new RuntimeException('database_unavailable');
        if (!is_file($path)) { if ($required) throw new RuntimeException('database_unavailable'); return null; }
        $db=new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(200); $db->exec('PRAGMA query_only=ON'); $db->exec('BEGIN'); return $db;
    }
    private function rows(SQLite3 $db,string $table,string $fields,string $where='',bool $required=true): iterable
    {
        $q=$db->prepare("SELECT type FROM sqlite_master WHERE name=? AND type IN ('table','view')"); $q->bindValue(1,$table); $r=$q->execute(); $type=$r->fetchArray(SQLITE3_ASSOC); $r->finalize(); $q->close();
        if (!$type) { if ($required) throw new RuntimeException('schema_unavailable'); return; } if ($type['type']!=='table') throw new RuntimeException('schema_unavailable');
        $q=$db->query('SELECT '.$fields.' FROM '.$table.$where.' LIMIT 100001');
        try { while ($r=$q->fetchArray(SQLITE3_ASSOC)) {
            if (++$this->readRows>100000) throw new RuntimeException('capacity_limit'); $this->budget();
            foreach ($r as $value) if (is_string($value)) $this->readBytes+=strlen($value);
            if ($this->readBytes>134217728) throw new RuntimeException('capacity_limit'); yield $r;
        } } finally { $q->finalize(); }
    }
    private function budget(): void { if (microtime(true)>=$this->deadline) throw new RuntimeException('capacity_limit'); }
}
