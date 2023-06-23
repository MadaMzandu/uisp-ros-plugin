<?php
include_once 'api_sqlite.php';
include_once 'api_ucrm.php';

class ApiAttributes
{
    private $_devices ;

    public function check($request): int
    {
        if(!$this->check_config()) return 0 ;
        $entity = $request->extraData->entity ?? $request ;
        $attributes = $entity->attributes ?? null ;
        $values = $this->extract($attributes);
        if(empty($values)) return 0 ;
        if(!$this->check_status($entity)) return -1;
        if(!$this->check_device($values)) return 0 ;
        if($this->check_mac($values)) return 1;
        if($this->check_username($values)) return 1 ;
        if($this->check_auto($values)) return 2;
        return 0 ;
    }

    private function check_username($values):bool
    {
        $user = $values['username'] ?? null ;
        return (bool) $user ;
    }

    public function check_auto($values): bool
    {
        $conf = $this->conf();
        $hotspot = $values['hotspot'] ?? false ;
        return $conf->auto_ppp_user || ($hotspot && $conf->auto_hs_user);
    }

    public function set_username($serviceId,$clientId): void
    {
        $ids = $this->attribute_id_map();
        $uid = $ids['pppoe_user_attr'] ?? null ;
        $pid = $ids['pppoe_pass_attr'] ?? null ;
        $values = [];
        if($uid)
        $values[] = ['customAttributeId' => $uid, 'value' =>
            sprintf('%s-%s',$this->string(5),$clientId)] ;
        if($pid) $values[] = ['customAttributeId' => $pid, 'value' => $this->string()];
        if($values) $this->ucrm()->patch('clients/services/'.$serviceId,['attributes' => $values]);
    }


    public function check_config(): bool
    {
        $assigned = $this->assigned_map();
        $device = $assigned['device_name_attr'] ?? null ;
        $user = $assigned['pppoe_user_attr'] ?? null ;
        $mac = $assigned['mac_addr_attr'] ?? null ;
        return $device && ($user || $mac);
    }

    private function string($length = 8): string
    {
        $chars = [
            'alpha' => 'abcdefghijklmnopqrstuvwxyz',
            'caps' => '',
            'num' => '0123456789',
            'sym' => '#$@!*?',
        ];
        $chars['caps'] = strtoupper($chars['alpha']);
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

    private function strip($attributes){
        if(!is_array($attributes)){ return []; }
        $values = [];
        $assigned = $this->assigned_map();
        foreach($attributes as $attribute){
            $native = $assigned[$attribute->key] ?? null;
            if($native)$values[$native] = $this->to_value($attribute->value ?? null);
        }
        return $values ;
    }

    private function to_value($value){
        if(empty($value)){ return null ;}
        return trim($value);
    }

    private function to_lower($value){
        if(empty($value)) return null ;
        if(!is_string($value)) return trim($value);
        return strtolower(trim($value));
    }

    private function check_mac($values): bool
    {
        $mac = $values['mac'] ?? null ;
        return filter_var($mac,FILTER_VALIDATE_MAC) ;
    }

    private function check_status($entity): bool
    {
        $status = $entity->status ?? 1 ;
        return !in_array($status,[2,5,8]);
    }

    private function check_device($values): bool
    {
        $name = $values['device'] ?? null ;
        if(!$name) return false ;
        $device = $this->db()->selectDeviceByDeviceName($name);
        return (bool) $device ;
    }

    public function extract($attributes): array
    {//extract attribute values and map to database keys
        $attributes = $this->strip($attributes);
        $dbmap = $this->dbmap();
        $devices = $this->device_map();
        $map = array_fill_keys(array_values($dbmap),null);
        foreach(array_keys($attributes) as $key){
            $dbkey = $dbmap[$key] ?? null ;
            if($dbkey){ $map[$dbkey] = $attributes[$key] ?? null; }
        }
        $device = $map['device'] ?? null;
        if($device)$map['device'] = $devices[$this->to_lower($device)] ?? null ;
        return $this->split($map) ;
    }

    private function split($values): array
    {// split for network table
        $keys = ['address','address6'];
        $map = array_diff_key($values,array_fill_keys($keys,null));
        foreach ($keys as $key){ $map['network'][$key] = $values[$key] ?? null; }
        return $map ;
    }

    private function device_map()
    {
        if(!empty($this->_devices)){
            return $this->_devices ;
        }
        $devices = $this->db()->selectAllFromTable('devices') ?? [];
        $this->_devices = [];
        foreach ($devices as $device){
            $name = $device['name'] ?? 'Noname';
            $this->_devices[$this->to_lower($name)] = $device['id'];
        }
        return $this->_devices ;
    }

    private function attribute_id_map(): array
    {// native key to uisp id
        $assigned = $this->assigned_map();
        $attributes = $this->attribute_map() ;
        $map = [];
        foreach(array_keys($assigned) as $native_key){
            $key = $assigned[$native_key] ?? null ;
            if($key){
                $attribute = $attributes[$key] ?? null ;
                if($attribute){$map[$native_key] = $attribute->id ; }
            }
        }
        return $map ;
    }

    private function attribute_map(): array
    { //key to attribute map
        $read = $this->ucrm()->get('custom-attributes') ?? [];
        $map = [];
        foreach ($read as $item){
            $map[$item->key] = $item ;
        }
        return $map ;
    }

    private function assigned_map(): array
    {// native key to assigned key map
        $conf = $this->conf();
        $native_keys = array_keys($this->dbmap());
        $map = [];
        foreach ($native_keys as $native_key){
            $assigned = $conf->$native_key ?? null ;
           if($assigned) $map[$assigned] = $native_key;
        }
        return $map ;
    }

    private function dbmap(): array
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

    private function db(){ return new ApiSqlite(); }

    private function conf(){ return $this->db()->readConfig(); }

    private function ucrm(){ return new ApiUcrm(); }

}