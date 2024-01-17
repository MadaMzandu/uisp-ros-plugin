<?php

include_once 'api_sqlite.php';
include_once 'api_ucrm.php';

class ApiAttributes
{
    private ?array $_devmap = null;
    private ?object  $_conf = null ;
    private ?array $_attrs = null ;

    public function set_user($serviceId, $clientId): void
    {
        $attrs = $this->get_attrs() ;
        $conf = $this->conf() ;
        $values = [];
        foreach(['user','pass'] as $nk){
            $key = $conf->{"pppoe_${nk}_attr"} ?? 'NoKey';
            $id = $attrs[$key]  ?? null ;
            $values[$nk] = ['customAttributeId' => $id];
        }
        $values['user']['value'] = sprintf('%s-%s',$this->string(5),$clientId);
        $values['pass']['value'] = $this->string();
        if($values) $this->ucrm()
            ->patch('clients/services/'.$serviceId,['attributes' => array_values($values)]);
    }

    public function check_config(): bool
    {
        $conf = $this->conf();
        $device = $conf->device_name_attr ?? null ;
        $user = $conf->pppoe_user_attr ?? null ;
        $mac = $conf->mac_addr_attr ?? null ;
        return $device && ($user || $mac);
    }

    private function string($length = 8): string
    {
        $alpha = 'abcdefghijklmnopqrstuvwxyz';
        $chars = [
            'alpha' => $alpha,
            'caps' => strtoupper($alpha),
            'num' => '0123456789',
            'sym' => '#$@!*?',
        ];
        $str = '';
        while (strlen($str) < $length) {
            foreach ($chars as $set) {
                if(strlen($str) >= $length){ continue; }
                $array = str_split($set);
                 $str .= $array[array_rand($array)];
            }
        }
        return $str ;
    }

    public function extract($attributes): array
    {//extract attribute values and map to database keys
        $fill = array_fill_keys($this->db_map(),null);
        $map = array_replace($fill,$this->strip($attributes));
        $devices = $this->dev_map();
        $device = $map['device'] ?? null ;
        if($device) $map['device'] = $devices[strtolower($device)] ?? null ;
        return $this->split($map) ;
    }

    private function split($values): array
    {// split for network table
        $fill = array_fill_keys(['address','address6'],'&^%#@');
        $net = array_intersect_key($values,$fill);
        $map = array_diff_key($values,$fill);
        if(preg_grep("#[\d.:a-fA-F]+#",$net)){
            $map['network'] = $net ;
        }
        return $map ;
    }

    private function strip($attributes): array
    {
        if(empty($attributes)){ return []; }
        $conf = json_decode(json_encode($this->conf()),true);
        $dbmap = $this->db_map() ;
        $stripped = [];
        foreach($attributes as $attr){
            $native = array_search($attr->key,$conf,true);
            $dbcol = $dbmap[$native] ?? null ;
            if($dbcol){
                $stripped[$dbcol] = empty($attr->value) ? null : trim($attr->value);
            }
        }
        return $stripped ;
    }

    private function dev_map(): array
    {
        if(empty($this->_devmap)){
            $this->_devmap = [];
            $devices = $this->db()->selectAllFromTable('devices') ?? [];
            foreach ($devices as $device){
                $name = $device['name'] ?? 'Noname';
                $this->_devmap[strtolower(trim($name))] = $device['id'] ?? null;
            }
        }
        return $this->_devmap ;
    }

    private function get_attrs(): array
    { //key to attribute map
        if(empty($this->_attrs)){
            $this->_attrs = $this->ucrm()->get('custom-attributes') ?? [];
        }
        $map = [];
        foreach($this->_attrs as $attr){
            $map[$attr->key] = $attr->id ;
        }
        return $map ;
    }

    private function db_map(): array
    { // plugin native key to database key map
        return [
            'device_name_attr' => 'device',
            'pppoe_user_attr' => 'username',
            'pppoe_pass_attr' => 'password',
            'mac_addr_attr' => 'mac',
            'hs_attr' => 'hotspot',
            'ip_addr_attr' => 'address',
            'ip_addr6_attr' => 'address6',
            'dhcp6_duid_attr' => 'duid',
            'dhcp6_iaid_attr' => 'iaid',
            'pppoe_caller_attr' => 'callerId',
        ];
    }

    private function db(): ApiSqlite { return mySqlite(); }

    private function conf(): ?object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf;
    }

    private function ucrm(): ApiUcrm { return new ApiUcrm(); }

}