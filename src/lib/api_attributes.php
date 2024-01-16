<?php
include_once 'api_sqlite.php';
//include_once '_web_ucrm.php';
include_once 'api_ucrm.php';

class ApiAttributes
{
    private $_devmap ;
    private $_conf ;
    private ?array $_attributes = null ;

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

    public function set_user($serviceId, $clientId): void
    {
        $uid = $this->native2id('pppoe_user_attr');
        $pid = $this->native2id('pppoe_pass_attr');
        $values = [];
        if($uid){
            $values[] = ['customAttributeId' => $uid, 'value' =>
                sprintf('%s-%s',$this->string(5),$clientId)] ;
        }

        if($pid) $values[] = ['customAttributeId' => $pid, 'value' => $this->string()];
        if($values) $this->ucrm()->patch('clients/services/'.$serviceId,['attributes' => $values]);
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
        $fill = array_fill_keys(array_values($this->dbmap()),null);
        $map = array_replace($fill,$this->strip($attributes));
        $devices = $this->devmap();
        $device = $map['device'] ?? null ;
        if($device) $map['device'] = $devices[strtolower($device)] ?? null ;
        return $this->split($map) ;
    }

    private function split($values): array
    {// split for network table
        $netkeys = ['address','address6'];
        $map = array_diff_key($values,array_fill_keys($netkeys,null));
        foreach ($netkeys as $key){ $map['network'][$key] = $values[$key] ?? null; }
        return $map ;
    }

    private function strip($attributes): array
    {
        if(empty($attributes)){ return []; }
        $stripped = [];
        foreach($attributes as $attr){
            $dbcol = $this->key2native($attr->key);
            $value = $attr->value ?? null ;
            $stripped[$dbcol] = $this->to_value($value);
        }
        return $stripped ;
    }

    private function to_value($value)
    {
        if(is_string($value)) return trim($value);
        return $value ;
    }

    private function devmap(): array
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

    private function get_attr(): array
    { //key to attribute map
        if(empty($this->_attributes)){
            $read = $this->ucrm()->get('custom-attributes') ?? [];
            $this->_attributes = [];
            foreach ($read as $item){
                $this->_attributes[$item->key] = $item ;
            }
        }
        return $this->_attributes ;
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

    private function db(): ApiSqlite { return mySqlite(); }

    private function key2native($key): ?string
    {
        $conf = json_decode(json_encode($this->conf()),true);
        $keys = array_keys($this->dbmap());
        $natives = array_intersect_key($conf,
            array_fill_keys($keys,null));
        $natives = array_diff_assoc($natives,array_fill_keys($keys,null));
        return array_flip($natives)[trim($key)] ?? null ;
    }

    public function native2id($native): ?int
    {
        foreach ($this->get_attr() as $attr){
            if($native == $this->key2native($attr->key)){
                return $attr->id ;
            }
        }
        return null ;
    }

    private function key2dbcol($key): ?string
    {
        $native = $this->key2native($key);
        return $this->dbmap()[$native] ?? null ;
    }

    private function conf(): ?object
    {
        if(empty($this->_conf)){
            $this->_conf = $this->db()->readConfig();
        }
        return $this->_conf;
    }

    private function ucrm(){ return new ApiUcrm(); }

}