<?php

/** Recover purged legacy identities from explicit ownership on their recorded router. Read only. */
final class ApiLifecycleRouterRecovery
{
    private $reader;
    private $api=null;
    private float $deadline;
    private const FIELDS = [
        'ppp/secret' => ['.id','name','comment','remote-address','remote-ipv6-prefix'],
        'ip/hotspot/user' => ['.id','name','comment','address'],
        'ip/dhcp-server/lease' => ['.id','mac-address','comment','address'],
        'ipv6/dhcp-server/binding' => ['.id','duid','iaid','comment','address'],
        'queue/simple' => ['.id','name','comment'],
        'ppp/profile' => ['.id','name','comment'],
        'ip/hotspot/user/profile' => ['.id','name','comment'],
        'ppp/active' => ['.id','name'],
        'ip/hotspot/active' => ['.id','user'],
    ];

    public function __construct(?callable $reader=null, ?float $deadline=null)
    { $this->reader=$reader; $this->deadline=$deadline??microtime(true)+12; }

    /** No username, MAC or IP in the desired cache grants permission to remove an account. */
    public function recover(array $legacy,array $device,?int $clientId=null): array
    {
        try { return $this->recoverObserved($legacy,$device,$clientId); }
        catch (Throwable $error) {
            if (in_array($error->getMessage(),['lifecycle_identity_changed','lifecycle_router_identity_unknown','lifecycle_changed','lifecycle_resources_unconfirmed'],true)) throw $error;
            throw new RuntimeException('lifecycle_resources_unconfirmed',0,$error);
        } finally { if ($this->api!==null) { $this->api->disconnect(); $this->api=null; } }
    }

    private function recoverObserved(array $legacy,array $device,?int $clientId): array
    {
        $sid=self::id($legacy['id']??null); $cid=$clientId??self::id($legacy['clientId']??null);
        $did=self::id($legacy['device']??null);
        if ($sid===null || $cid===null || $did===null || self::id($device['id']??null)!==$did
            || (self::id($legacy['clientId']??null)!==null && self::id($legacy['clientId'])!==$cid))
            throw new RuntimeException('lifecycle_identity_changed');
        $this->validateDevice($device);
        $catalog=$this->catalog($device); $signature=$this->signature($catalog,$sid,$cid);
        $commands=[]; $sessions=[]; $principals=[];
        $sessionObservations=[];
        foreach ($catalog as $path=>$rows) {
            $selectors=[];
            foreach ($rows as $row) {
                $selector=$this->selector($path,$row);
                if ($selector!==null) $selectors[json_encode($selector,JSON_THROW_ON_ERROR)][]=$row;
            }
            foreach ($rows as $row) {
                $owner=$this->owner($row, $sid,$cid,$path);
                if ($owner===null) continue;
                $selector=$this->selector($path,$row);
                if ($selector===null || count($selectors[json_encode($selector,JSON_THROW_ON_ERROR)]??[])!==1)
                    throw new RuntimeException('lifecycle_router_identity_unknown');
                $command=['path'=>'/'.$path,'action'=>'remove']+$selector;
                if ($owner==='account') {
                    $command['_service_id']=$sid; $command['_client_id']=$cid;
                    if (in_array($path,['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease'],true)) $principals[]=[$path,$row];
                    if (in_array($path,['ppp/secret','ip/hotspot/user'],true)) {
                        $session=$path==='ppp/secret'?'ppp/active':'ip/hotspot/active';
                        $field=$session==='ppp/active'?'name':'user';
                        $sessionObservations[]=[$session,[$field=>$row['name']],$this->read($device,$session,[$field=>$row['name']])];
                        $sessions[]=['path'=>'/'.$session,'name'=>$row['name'],'action'=>'remove',
                            '_service_id'=>$sid,'_client_id'=>$cid,'_reconnect_required'=>true];
                    }
                } else $command['_owner']=$owner;
                $commands[]=$command;
            }
        }
        // Absence alone cannot reconstruct an unknown historical account or its surviving session.
        if (!$principals || count($commands)+count($sessions)>60) throw new RuntimeException('lifecycle_router_identity_unknown');
        if (!hash_equals($signature,$this->signature($this->catalog($device),$sid,$cid))) throw new RuntimeException('lifecycle_changed');
        foreach ($sessionObservations as [$session,$filter,$observed])
            if ($this->canonical($observed)!==$this->canonical($this->read($device,$session,$filter))) throw new RuntimeException('lifecycle_changed');
        // Retire dedicated profiles before their parent queues. Shared resources are never selected.
        usort($commands,static function(array $a,array $b): int {
            $rank=static function(string $path): int {
                if (in_array($path,['/ppp/profile','/ip/hotspot/user/profile'],true)) return 1;
                return $path==='/queue/simple'?2:0;
            };
            return $rank($a['path'])<=>$rank($b['path']);
        });
        [$path,$principal]=$principals[0];
        $snapshot=$legacy;
        foreach (['username','password','mac','hotspot','duid','iaid','_commands','_cleanup'] as $field) unset($snapshot[$field]);
        $snapshot['id']=$sid; $snapshot['clientId']=$cid; $snapshot['device']=$did; $snapshot['_device']=$device;
        if ($path==='ip/dhcp-server/lease') $snapshot['mac']=strtoupper($principal['mac-address']);
        else { $snapshot['username']=$principal['name']; $snapshot['hotspot']=$path==='ip/hotspot/user'; }
        $snapshot['action']='delete'; $snapshot['_commands']=array_merge($commands,$sessions);
        $snapshot['_delete_frozen']=true;
        return [$snapshot];
    }

    private function owner(array $row,int $sid,int $cid,string $path): ?string
    {
        $comment=$row['comment']??''; $name=$row['name']??null;
        if (!is_string($comment)) throw new RuntimeException('lifecycle_router_identity_unknown');
        if (preg_match('/^([1-9][0-9]*) - ([^\x00-\x1f\x7f]+) - ([1-9][0-9]*)$/D',$comment,$match)===1) {
            if (self::id($match[3])!==$sid) return null;
            if (self::id($match[1])!==$cid) throw new RuntimeException('lifecycle_identity_changed');
            if (in_array($path,['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding','queue/simple'],true)) return 'account';
            return null;
        }
        $managed=$path==='queue/simple' ? ['suspension','service-parent']
            : (in_array($path,['ppp/profile','ip/hotspot/user/profile'],true)?['service-profile']:[]);
        foreach ($managed as $kind) if ($comment==='uisp-ros-plugin:'.$kind.':'.$sid) {
            if (($kind==='suspension' && $name!=='uisp-service-'.$sid.'-suspension')
                || ($kind==='service-parent' && $name!=='uisp-service-'.$sid.'-parent'))
                throw new RuntimeException('lifecycle_router_identity_unknown');
            return $comment;
        }
        if (in_array($path,['ppp/profile','ip/hotspot/user/profile'],true) && $comment==='Servicio #'.$sid) {
            // Same reserved-name rules as MtData::serviceProfileNameMatches; no provisioning classes needed for injected reads.
            $suffix=' [Override S#'.$sid.']';
            $matches=is_string($name) && preg_match('//u',$name)===1 && !preg_match('/[\x00-\x1f\x7f]/',$name)
                && ($name==='uisp-service-'.$sid.'-profile' || (strlen($name)>strlen($suffix)
                    && substr($name,-strlen($suffix))===$suffix && trim(substr($name,0,-strlen($suffix)))!==''));
            if (!$matches) throw new RuntimeException('lifecycle_router_identity_unknown');
            return 'uisp-ros-plugin:service-profile:'.$sid;
        }
        // A damaged marker claiming this service is not treated as absence.
        if (preg_match('/ - '.preg_quote((string)$sid,'/').'\s*$/D',$comment)===1)
            throw new RuntimeException('lifecycle_router_identity_unknown');
        return null;
    }

    private function catalog(array $device): array
    {
        $catalog=[];
        foreach (['ppp/secret','ip/hotspot/user','ip/dhcp-server/lease','ipv6/dhcp-server/binding','queue/simple',
            'ppp/profile','ip/hotspot/user/profile'] as $path) $catalog[$path]=$this->read($device,$path);
        return $catalog;
    }

    private function signature(array $catalog,int $sid,int $cid): string
    {
        $owned=[];
        foreach ($catalog as $path=>$rows) {
            $selectors=[];
            foreach ($rows as $row) {
                $selector=$this->selector($path,$row);
                if ($selector!==null) $selectors[json_encode($selector,JSON_THROW_ON_ERROR)][]=$row;
            }
            foreach ($rows as $row) if ($this->owner($row,$sid,$cid,$path)!==null) {
                $selector=$this->selector($path,$row);
                if ($selector===null || count($selectors[json_encode($selector,JSON_THROW_ON_ERROR)]??[])!==1)
                    throw new RuntimeException('lifecycle_router_identity_unknown');
                $owned[]=['path'=>$path,'row'=>$row];
            }
        }
        return hash('sha256',$this->canonical($owned));
    }

    private function canonical(array $rows): string
    {
        $values=[];
        foreach ($rows as $row) { if (isset($row['row'])) ksort($row['row']); ksort($row); $values[]=json_encode($row,JSON_THROW_ON_ERROR); }
        sort($values,SORT_STRING); return json_encode($values,JSON_THROW_ON_ERROR);
    }

    private function selector(string $path,array $row): ?array
    {
        if ($path==='ip/dhcp-server/lease') return filter_var($row['mac-address']??'',FILTER_VALIDATE_MAC)
            ? ['mac-address'=>strtoupper($row['mac-address'])] : null;
        if ($path==='ipv6/dhcp-server/binding') {
            $duid=$row['duid']??null; $iaid=$row['iaid']??null;
            if (!is_string($duid) || !preg_match('/^(?:0x)?(?:[0-9a-fA-F]{2}[:-]?){6,}$/D',$duid)
                || !is_string($iaid) || !preg_match('/^(?:0|[1-9][0-9]*)$/D',$iaid) || (float)$iaid>4294967295) return null;
            return ['duid'=>$duid,'iaid'=>$iaid];
        }
        return self::text($row['name']??null,256) && $row['name']!=='' ? ['name'=>$row['name']] : null;
    }

    private function read(array $device,string $path,array $filter=[]): array
    {
        if (!isset(self::FIELDS[$path]) || microtime(true)>=$this->deadline) throw new RuntimeException('lifecycle_router_unavailable');
        $fields=self::FIELDS[$path];
        $rows=$this->reader!==null ? ($this->reader)($device,$path,$filter,$fields) : $this->nativeRead($device,$path,$filter,$fields);
        if (!is_array($rows) || array_keys($rows)!==array_keys(array_values($rows)) || count($rows)>10000
            || microtime(true)>=$this->deadline) throw new RuntimeException('lifecycle_router_unavailable');
        $seen=[];
        foreach ($rows as &$row) {
            if (!is_array($row) || !is_string($row['.id']??null) || !preg_match('/^\*[a-fA-F0-9]+$/D',$row['.id'])
                || isset($seen[$row['.id']])) throw new RuntimeException('lifecycle_router_unavailable');
            $seen[$row['.id']]=true; $row=array_intersect_key($row,array_fill_keys($fields,true));
            foreach ($row as $value) if (!is_string($value) || !self::text($value,2048)) throw new RuntimeException('lifecycle_router_unavailable');
            foreach ($filter as $key=>$value) if (($row[$key]??null)!==$value) throw new RuntimeException('lifecycle_router_unavailable');
        } unset($row);
        return $rows;
    }

    private function nativeRead(array $device,string $path,array $filter,array $fields): array
    {
        require_once __DIR__.'/routeros_api.class.php';
        $api=$this->api??new RouterosAPI(); $api->timeout=2; $api->attempts=1; $api->debug=false;
        $api->port=(int)($device['port']??8728); $api->ssl=filter_var($device['ssl']??($api->port===8729),FILTER_VALIDATE_BOOLEAN);
        $api->allow_self_signed=filter_var($device['allowSelfSigned']??false,FILTER_VALIDATE_BOOLEAN);
        $this->api=$api;
        return $api->withReadDeadline($this->deadline,function() use ($api,$device,$path,$filter,$fields): array {
                if (!$api->connected && !$api->connect($device['ip'],$device['user'],$device['password']??'')) throw new RuntimeException('lifecycle_router_unavailable');
                $api->write('/'.$path.'/print',false); $api->write('=.proplist='.implode(',',$fields),false);
                foreach ($filter as $key=>$value) $api->write('?'.$key.'='.$value,false);
                $api->finishSentence(); return $this->decode($api->read(false),$fields);
            });
    }

    private function decode($words,array $fields): array
    {
        if (!is_array($words) || array_keys($words)!==array_keys(array_values($words)) || !$words || count($words)>120000 || end($words)!=='!done'
            || count(array_keys($words,'!done',true))!==1) throw new RuntimeException('lifecycle_router_unavailable');
        if ($words===['!done'] || $words===['!empty','!done']) return [];
        $rows=[]; $row=null;
        foreach ($words as $word) {
            if (!is_string($word) || strlen($word)>4096) throw new RuntimeException('lifecycle_router_unavailable');
            if ($word==='!re' || $word==='!done') { if ($row!==null) $rows[]=$row; $row=$word==='!re'?[]:null; continue; }
            if ($row===null || !preg_match('/^=([^=]+)=(.*)$/sD',$word,$match)) throw new RuntimeException('lifecycle_router_unavailable');
            if (!in_array($match[1],$fields,true)) continue;
            if (array_key_exists($match[1],$row)) throw new RuntimeException('lifecycle_router_unavailable');
            $row[$match[1]]=$match[2];
        }
        return $rows;
    }

    private function validateDevice(array $device): void
    {
        $port=filter_var($device['port']??8728,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        if (!self::text($device['ip']??null,512) || trim($device['ip'])==='' || !self::text($device['user']??null,256)
            || $device['user']==='' || !is_string($device['password']??null) || $port===false
            || !in_array($device['type']??'mikrotik',[null,'','mikrotik'],true)
            || filter_var($device['ssl']??($port===8729),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)===null
            || filter_var($device['allowSelfSigned']??false,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)===null)
            throw new RuntimeException('lifecycle_router_identity_unknown');
    }
    private static function text($value,int $limit): bool
    { return is_string($value) && strlen($value)<=$limit && !preg_match('/[\x00-\x1f\x7f]/',$value); }
    private static function id($value): ?int
    { if (is_bool($value)||is_float($value)) return null; $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); return $id===false?null:$id; }
}
