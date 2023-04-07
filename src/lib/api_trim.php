<?php
include_once 'api_attributes.php';

class ApiTrim
{// trim request to min and map to db fields

    public function trim($type, $request): ?array
    {
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
        $entity = array_fill_keys(['id','company','firstName','lastName'],null);
        foreach (array_keys($entity) as $key){ $entity[$key] = $item->$key ?? null; }
        return ['entity' => $entity];
    }

    private function trim_service($request): ?array
    {
        $entity = $request->extraData->entity ?? $request;
        $previous = $request->extraData->entityBeforeEdit ?? null ;
        $return['action'] = $request->changeType ?? 'insert' ;
        $return['entity'] = $this->extract($entity);
        $return['previous'] = $this->extract($previous);
        return $return;
    }

    private function extract($item)
    {
        $attributes = $item->attributes ?? [];
        $entity = $this->attributes()->extract($attributes);
        $fields = [
            'id',
            'servicePlanId',
            'clientId',
            'status',
            'price',
            'totalPrice',
            'currencyCode',
        ];
        $db_map = ['servicePlanId' => 'planId'];
        foreach ($fields as $field) {
            $db_key = $db_map[$field] ?? $field;
            $entity[$db_key] = $item->$field ?? null;
        }
        return $entity ;
    }

    private function attributes(){ return new ApiAttributes(); }

    private function db(){ return new ApiSqlite(); }

    private function conf(){return $this->db()->readConfig() ; }

}
