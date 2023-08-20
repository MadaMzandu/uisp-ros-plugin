<?php
include_once 'api_ip.php';
function strim($str){ return !$str ? $str :trim(trim($str),'>');}

class ErQueue
{
    private $data ;

    public function toArray(): array { return array_replace($this->data,[]); }
    public function reset($ip,$limits,$disabled,$drate): void { $this->configure($ip,$limits,$disabled,$drate);}

    private function set_rates($value): void
    {
        if(is_array($value)){
            $map = ['burst' => 'brate'];
            foreach(['rate'] as $key){
                $a = $value[$key] ?? null ;
                if(!$a || sizeof($a) < 2){ continue; }
                [$f,$r] = $a ;
                $mapped = $map[$key] ?? $key ;
                $this->data[$mapped] = $f . 'Mbit';
                $this->data['r_'.$mapped] = $r . 'Mbit';
            }
        }
    }

    private function set_disabled($disabled,$rate): void
    {
        if($disabled){
            $this->data['rate'] = $rate . 'Mbit';
            $this->data['r_rate'] = $rate . 'Mbit';
        }
    }

    private function set_qtype(): void
    {
        $this->data['qtype'] = 'pfifo';
        $this->data['r_qtype'] = 'pfifo';
    }

    private function defaults(): array
    {
        $fields = 'src,dest,application,rate,brate,bsize,qtype,r_rate,r_brate,r_bsize,'.
            'r_qtype,hfq_subnet,hfq_max,hfq_id,hfq_brate,hfq_bsize,r_hfq_subnet,r_hfq_max,'.
            'r_hfq_id,r_hfq_brate,r_hfq_bsize,app,app_custom';
        return array_fill_keys(explode(',',$fields),"");
    }

    private function configure($ip,$limits,$disabled,$drate): void
    {
        $this->data = $this->defaults();
        $this->data['src'] = $ip . '/32';
        $this->set_rates($limits);
        $this->set_qtype();
        $this->set_disabled($disabled,$drate);
        $this->data['path'] = 'queue';
    }

    public function __construct($ip, $limits, $disabled=false, $disrate = 0){
        $this->configure($ip,$limits,$disabled,$disrate); }


}

class ErObject
{// create and manage a configuration tree
    private array $_edit;
    private string $_base ;
    private ?array $_read = null;
    private object $_device ;

    public function __construct($device,$base){$this->_edit = $this->path2arr($base);
        $this->_device = $device; $this->_base = strim($base);  }

    public function __get($name){ return $this->_edit[$name]; }

    public function reset(): void { $this->_edit = $this->path2arr($this->_base); }

    public function post(): array { return array_replace([],$this->_edit); }

    public function json($key = 'struct'): array { return [$key => json_encode($this->_edit)]; }

    public function findKeys($path = null){ return array_keys($this->findPath($path)); }

    public function read($path = null): bool
    {
        $this->_read = null ;
        $fp = sprintf('%s>%s',$this->_base,strim($path));
        $jsonpath = json_encode($this->path2arr(strim($fp)));
        $r = $this->client()->get('partial.json',['struct' => $jsonpath]);
        $s = $r['success'] ?? false ;
        if(!$s){ return false; }
        $this->_read = $r['GET'] ?? null;
        return (bool) $this->_read ;
    }

    private function client(): ?ErClient
    {
        $c = erClient();
        $d = &$this->_device;
        $p = $d->port ?? 443;
        if(!$c->connect($d->user,$d->password,$d->ip,$p)){ return null; }
        return $c ;
    }

    private function path2arr($path): array
    {//convert this>type>of>path to an array tree
        $components = explode('>',strim($path));
        $array = [] ;
        $ref = &$array ;
        foreach($components as $c) {
            $ref[$c] = [];
            $ref = &$ref[$c] ; }
        $ref = null;
        return $array;
    }

    public function findPath($path = null): array
    {
        $fp = sprintf('%s>%s',$this->_base,strim($path));
        $components = explode('>',strim($fp));
        $ref = &$this->_read;
        foreach ($components as $c){ $ref = &$ref[$c]; }
        return $ref ?? [];
    }

    public function set($key,$value,$path = null): void
    {// set value in the edit tree using a path
        $fp = sprintf('%s>%s',$this->_base,strim($path));
        $components = explode('>',strim($fp));
        $ref = &$this->_edit ;
        foreach ($components as $c) { $ref = &$ref[$c]; }
        $ref[$key] = $value ;
    }
}