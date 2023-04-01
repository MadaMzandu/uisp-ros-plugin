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

    private function trim_service($request): array
    {
        $action = $request->changeType ?? 'insert';
        $item = $request->extraData->entity ?? $request;
        $previous = $request->extraData->entityBeforeEdit ?? null ;
        $return = [];
        $entity = [];
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
        foreach($this->native_map() as $db_key){ $entity[$db_key] = null; } //for db fill keys will nulls
        foreach ($attributes as $attribute) {
            $key = $this->map_attr_key($attribute->key);
            if($key){$entity[$key] = $attribute->value ?? null; }
        }
        $dev_name = $entity['device'] ?? null ;
        if($dev_name){
            $dev_name = strtolower($dev_name);
            $entity['device'] = $this->devices[$dev_name]['id'] ?? null ;
        }
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

    private function native_map(): array
    {
        return [
            'device_name_attr' => 'device',
            'pppoe_user_attr' => 'username',
            'pppoe_pass_attr' => 'password',
            'mac_addr_attr' => 'mac',
            'hs_attr' => 'hotspot',
            'ip_addr_attr' => 'address',
            'pppoe_caller_attr' => 'callerId',
        ];
    }

    private function trim_previous($data): ?array
    {
        $previous = [];
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
            if($key) $previous[$key] = $attribute->value ;
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
        $data = $this->db()->selectAllFromTable('devices');
        $map = [];
        foreach ($data as $device) {
            $name = strtolower($device['name']);
            $map[$name] = $device;
        }
        return $this->devices = $map;
    }

    private function db(){ return new ApiSqlite(); }

    private function conf(){return $this->db()->readConfig() ; }

}
