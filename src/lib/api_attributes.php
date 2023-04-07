<?php
include_once 'api_sqlite.php';
include_once '_web_ucrm.php';
include_once 'api_ucrm.php';

class ApiAttributes
{

    public function check($request): bool
    {
        if(!$this->check_config()) return false ;
        $entity = $request->extraData->entity ?? $request ;
        $attributes = $entity->attributes ?? null ;
        $values = $this->extract($attributes);
        if(empty($values)) return false ;
        if(!$this->check_device($values)) return false ;
        if($this->check_mac($values)) return true ;
        return $this->check_username($values);
    }

    private function check_username($values):bool
    {
        $user = $values['username'] ?? null ;
        return (bool) $user ;
    }

    private function auto_user($values): bool
    {
        $conf = $this->conf();
        $hotspot = $values['hotspot'] ?? false ;
        return $conf->auto_ppp_user || ($hotspot && $conf->auto_hs_user);
    }

    public function set_username($cid): void
    {
        $ids = $this->attribute_id_map();
        $uid = $ids['pppoe_user_attr'] ?? null ;
        $pid = $ids['pppoe_pass_attr'] ?? null ;
        $post = [];
        if($uid)
        $post['attributes'][] = ['customAttributeId' => $uid, 'value' =>
            sprintf('%s-%s',$this->string(5),$cid)] ;
        if($pid) $post['attributes'][] = ['customAttributeId' => $pid, 'value' => $this->string()];
        if($post) $this->ucrm()->patch('clients/'.$cid,$post);
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

    private function check_mac($values): bool
    {
        $mac = $values['mac'] ?? null ;
        return filter_var($mac,FILTER_VALIDATE_MAC) ;
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
        if(empty($attributes)){ $attributes = []; }
        $assigned = array_flip($this->assigned_map());
        $native = $this->native_map();
        $devices = $this->device_map();
        $map = array_fill_keys(array_values($native),null);
        foreach($attributes as $attribute){
            $key = $assigned[$attribute->key] ?? null ;
            $db_key = $native[$key] ?? null ;
            if($db_key){ $map[$db_key] = $attribute->value ?? null; }
        }
        $device = strtolower($map['device']) ?? null ;
        $map['device'] = $devices[$device] ?? null ;
        return $map ;
    }

    private function device_map()
    {
        $devices = $this->db()->selectAllFromTable('devices');
        $map = [];
        foreach ($devices as $device){
            $name = strtolower($device['name']);
            $map[$name] = $device['id'];
        }
        return $map ;
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
        $native_keys = array_keys($this->native_map());
        $map = [];
        foreach ($native_keys as $native_key){
            $assigned = $conf->$native_key ?? null ;
           if($assigned) $map[$native_key] = $assigned;
        }
        return $map ;
    }

    private function native_map(): array
    { // plugin native key to database key map
        return [
            'device_name_attr' => 'device',
            'pppoe_user_attr' => 'username',
            'pppoe_pass_attr' => 'password',
            'mac_addr_attr' => 'mac',
            'hs_attr' => 'hotspot',
            'ip_addr_attr' => 'address',
            'ip_addr6_attr' => 'address6',
            'ip_routes_attr' => 'routes',
            'ip_routes6_attr' => 'routes6',
            'pppoe_caller_attr' => 'callerId',
        ];
    }

    private function db(){ return new ApiSqlite(); }

    private function conf(){ return $this->db()->readConfig(); }

    private function ucrm(){ return new WebUcrm(); }

}