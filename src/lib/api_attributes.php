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
            $key = $conf->{"pppoe_{$nk}_attr"} ?? 'NoKey';
            $id = $attrs[$key]  ?? null ;
            if (!$id) throw new RuntimeException('credential_attribute_missing');
            $values[$nk] = ['customAttributeId' => $id];
        }
        $service = $this->ucrm()->get('clients/services/' . (int) $serviceId);
        foreach ($service->attributes ?? [] as $attribute) {
            foreach (['user','pass'] as $field) {
                if ($attribute->customAttributeId == $values[$field]['customAttributeId'] && isset($attribute->value) && $attribute->value !== '') $values[$field]['value'] = $attribute->value;
            }
        }
        $values['user']['value'] = $values['user']['value'] ?? sprintf('%s-%s', $this->string(8), $serviceId);
        $values['pass']['value'] = $values['pass']['value'] ?? $this->string(16);
        if($values) {
           $done = $this->ucrm()
                ->patch('clients/services/'.$serviceId,['attributes' => array_values($values)]);
          if (!is_object($done) || !isset($done->id)) throw new RuntimeException('credential_update_failed');
        }
    }

    public function check_config(): bool
    {
        $conf = $this->conf();
        $device = $conf->device_name_attr ?? null ;
        $user = $conf->pppoe_user_attr ?? null ;
        $mac = $conf->mac_addr_attr ?? null ;
        return $device !== null && $device !== ''
            && (($user !== null && $user !== '') || ($mac !== null && $mac !== ''));
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
                 $str .= $array[random_int(0, count($array) - 1)];
            }
        }
        return $str ;
    }

    public function extract($attributes): array
    {//extract attribute values and map to database keys
        $fill = array_fill_keys($this->db_map(),null);
        $map = array_replace($fill,$this->strip($attributes));
        $device = $map['device'] ?? null ;
        if ($device !== null && $device !== '') {
            $devices = $this->dev_map();
            $id = $devices[strtolower($device)] ?? null;
            // A selected but unknown/ambiguous Site is a routing error, never a request
            // to detach the service and delete its previously provisioned account.
            if ($id === null) throw new InvalidArgumentException('service_site_not_configured');
            $map['device'] = $id;
        }
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
            if (!is_object($attr) || !isset($attr->key)) { continue; }
            $native = array_search($attr->key,$conf,true);
            $dbcol = $dbmap[$native] ?? null ;
            if($dbcol){
                $value = $attr->value ?? null;
                if ($value !== null && !is_scalar($value)) { throw new InvalidArgumentException('invalid_service_attribute_value'); }
                // RouterOS credentials are literal strings. In particular "0" and surrounding spaces are valid.
                if ($value !== null && !in_array($dbcol, ['username','password'], true)) { $value = trim((string) $value); }
                elseif ($value !== null) { $value = (string) $value; }
                $stripped[$dbcol] = $value === '' ? null : $value;
            }
        }
        return $stripped ;
    }

    private function dev_map(): array
    {
        if($this->_devmap === null){
            $this->_devmap = [];
            $devices = $this->db()->selectAllFromTable('devices') ?? [];
            foreach ($devices as $device){
                $name = strtolower(trim((string) ($device['name'] ?? '')));
                if ($name === '') continue;
                $id = filter_var($device['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                // Normalized duplicates cannot be resolved safely by row order.
                $this->_devmap[$name] = array_key_exists($name, $this->_devmap) || $id === false ? null : $id;
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

$apiAttributes = null ;

function myAttr(): ApiAttributes
{
    global $apiAttributes ;
    if(empty($apiAttributes)){
        $apiAttributes = new ApiAttributes();
    }
    return $apiAttributes ;
}
