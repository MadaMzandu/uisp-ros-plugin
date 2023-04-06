<?php
include_once 'api_sqlite.php';
include_once '_web_ucrm.php';
include_once 'api_ucrm.php';

class ApiAttributes
{
    private $cid = 0 ;
    private $uid ;
    private $pid ;

    public function check($request): bool
    {
        $this->check_config();
        $entity = $request->extraData->entity ?? $request ;
        $attributes = $entity->attributes ?? null ;
        $this->cid = $entity->clientId ;
        $values = $this->map_attributes($attributes);
        if(empty($values)) return false ;
        if(!$this->check_device($values)) return false ;
        if($this->check_mac($values)) return true ;
        return $this->check_username($values);
    }

    private function check_username($values):bool
    {
        $user = $values['username'] ?? null ;
        if($user) return true ;
        $conf = $this->conf();
        $hotspot = $values['hotspot'] ?? false ;
        $auto = $conf->auto_ppp_user || ($hotspot && $conf->auto_hs_user);
        if(!$auto) return false ;
        $this->set_username();
        return false ;
    }

    public function set_username(): void
    {
        $user = ['customAttributeId' => $this->uid,
            'value' => sprintf('%s-%s',$this->string(5),$this->cid)] ;
        $pass = ['customAttributeId' => $this->pid, 'value' => $this->string()];
        $post = ['attributes' => [$user,$pass]];
        $this->ucrm()->patch('clients/'.$this->cid,$post);
    }

    private function check_config()
    {
        $user_key = $this->conf()->pppoe_user_attr ?? null ;
        $pass_key = $this->conf()->pppoe_pass_attr ?? null ;
        if(!($user_key && $pass_key)){
            throw new Exception('attributes: plugin not ready - missing custom attributes');
        }
        $attrs = $this->ucrm()->get('custom-attributes',['attributeType' => 'service']);
        foreach ($attrs as $attr){
            if($attr->key == $user_key) $this->uid = $attr->id ;
            if($attr->key == $pass_key) $this->pid = $attr->id ;
        }
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

    private function map_attributes($attributes): ?array
    {
        if(empty($attributes)) return null ;
        $native_map = $this->native_map() ;
        $conf = $this->conf();
        $map = [];
        foreach ($attributes as $attribute){
            foreach (array_keys($native_map) as $native_key){
                $assigned = $conf->$native_key ?? null;
                if($assigned && $assigned == $attribute->key){
                    $key = $native_map[$native_key];
                    $map[$key] = $attribute->value ;
                }
            }
        }
        return $map ;
    }

    private function native_map(): array
    {
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