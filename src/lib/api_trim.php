<?php

class ApiTrim
{// trim request to min and map to db fields
    private $devices ;

    public function trim($type, $request): ?array
    {
        if(!$this->devices){ $this->set_devices();}
        switch (strtolower($type)){
            case 'service':
            case 'services': return $this->trim_service($request);
            case 'client':
            case 'clients': return $this->trim_client($request);
        }
        return null ;
    }

    private function trim_client($request): array
    {
        $item = $request->extraData->entity ?? $request ;
        $entity = [];
        $fields = [
            'id',
            'company',
            'firstName',
            'lastName',
        ];
        foreach ($fields as $key){ $entity[$key] = $item->$key ?? null; }
        return ['entity' => $entity];
    }

    private function trim_service($request): ?array
    {
        $action = $request->changeType ?? 'insert';
        $item = $request->extraData->entity ?? $request;
        $previous = $request->extraData->entityBeforeEdit ?? null ;
        $return = [];
        $entity = $this->default_service();
        $fields = [
            'id',
            'servicePlanId',
            'clientId',
            'status',
            'price',
            'totalPrice',
            'currencyCode',
        ];
        $map = ['servicePlanId' => 'planId'];
        foreach ($fields as $field) {
            $db_key = $map[$field] ?? $field;
            $entity[$db_key] = $item->$field ?? null;
        }
        $attributes = $item->attributes ?? [];
        foreach ($attributes as $attribute) {
            $key = $this->map_attr_key($attribute->key);
            if($key){
                if(in_array($key,['address','address6','routes','routes6'])){// network
                    $entity['network'][$key] = $attribute->value ?? null;
                }
                else{
                    $entity[$key] = $attribute->value ?? null;
                }
            }
        }
        $dev_name = $entity['device'] ?? null ;
        if($dev_name){
            $dev_name = strtolower($dev_name);
            $entity['device'] = $this->devices[$dev_name]['id'] ?? null ;
        }
        if(!$this->check_attributes($entity)){ return null; }
        $return['action'] = $action ;
        $return['entity'] = $entity ;
        if($previous) { $return['previous'] = $this->trim_previous($previous); }
        return $return;
    }

    private function map_attr_key($key): ?string
    {
        $native_map = $this->native_map() ;
        foreach (array_keys($native_map) as $native_key){
            $assigned = $this->conf()->$native_key ?? null ;
            if($assigned && $assigned == $key){
                return $native_map[$native_key] ?? null;
            }
        }
        return null ;
    }

    private function check_attributes($entity)
    {
        $dev = $entity['device'] ?? null;
        $account = $entity['mac'] ?? $entity['username'] ?? null;
        return $dev && $account ;
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

    private function trim_previous($data): ?array
    {
        $previous = $this->default_service();
        $fields = [
            'id',
            'status',
            'servicePlanId',
        ];
        $map = ['servicePlanId' => 'planId'];
        foreach ($fields as $field){
            $key = $map[$field] ?? $field ;
            $previous[$key] = $data->$field ?? null ;
        }
        $attributes = $data->attributes ?? [];
        foreach ($attributes as $attribute){
            $key = $this->map_attr_key($attribute->key);
            if($key){
                if(in_array($key,['address','address6'])){// network
                    $entity['network'][$key] = $attribute->value ?? null;
                }
                else{
                    $entity[$key] = $attribute->value ?? null;
                }
            }
        }
        $dev_name = $previous['device'] ?? null ;
        if($dev_name){
            $dev_name = strtolower($dev_name);
            $previous['device'] = $this->devices[$dev_name]['id'] ?? null ;
        }
        return empty($previous) ? null : $previous ;
    }

    private function set_devices(): array
    {
        $data = $this->db()->selectAllFromTable('devices') ?? [];
        $map = [];
        foreach ($data as $device) {
            $name = strtolower($device['name']);
            $map[$name] = $device;
        }
        return $this->devices = $map;
    }

    private function default_service():array
    {
        $keys = ["id","planId","clientId","status","price","totalPrice",
            "currencyCode","device","username","password","mac","hotspot","callerId"];
        $entity = [];
        foreach ($keys as $key) { $entity[$key] = null; }
        $entity['network'] = $this->default_network();
        return $entity ;
    }

    private function default_network(): array
    {
        $keys = [
            'address','address6','routes','routes6'
        ];
        $net = [];
        foreach ($keys as $key){ $net[$key] = null; }
        return $net ;
    }

    private function db(){ return new ApiSqlite(); }

    private function conf(){return $this->db()->readConfig() ; }

}
