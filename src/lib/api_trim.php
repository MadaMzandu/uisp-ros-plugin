<?php
include_once 'api_attributes.php';

class ApiTrim
{// trim request to min and map to db fields

    public function trim($type, $request): ?array
    {
        return match (strtolower($type)) {
            'service', 'services' => $this->trim_service($request),
            'client', 'clients' => $this->trim_client($request),
            default => null,
        };
    }

    private function trim_site($request): array
    {
        $site['id'] = $request->id ?? 'nosite';
        $site['service'] = $request->ucrm->service->id ?? 0 ;
        $site['device'] = $request->device ?? null;
        return ['entity' => $site];
    }

    private function trim_client($request): array
    {
        $item = $request->extraData->entity ?? $request ;
        $array = json_decode(json_encode($item),true);
        $entity = array_fill_keys(['id','firstName','lastName'],null);
        $entity = array_intersect_key($array,$entity);
        $entity['company'] = $item->companyName ?? null ;
        return ['entity' => $entity];
    }

    private function trim_service($request): ?array
    {
        $entity = $request->extraData->entity ?? $request;
        $previous = $request->extraData->entityBeforeEdit ?? null ;
        $return['action'] = $request->changeType ?? 'insert' ;
        $return['entity'] = $this->extract($entity);
        if($previous){
            $return['previous'] = $this->extract($previous);
        }
        return $return;
    }

    private function extract($item): array
    {
        $entity = $this->attrs()->extract($item->attributes ?? []);
        $fields = "id,clientId,status,uploadSpeed,".
            "downloadSpeed,price,totalPrice,currencyCode";
        $array = json_decode(json_encode($item),true);
        $trim = array_intersect_key($array,
            array_fill_keys(explode(',',$fields),null));
        $entity = array_replace($entity,$trim);
        $entity['planId'] = $item->servicePlanId ?? null;
        return $entity ;
    }

    private function attrs(): ApiAttributes
    {
        return myAttr();
    }

}

$apiTrimmer = null ;

function myTrimmer(): ApiTrim
{
    global $apiTrimmer ;
    if(empty($apiTrimmer)){
        $apiTrimmer = new ApiTrim() ;
    }
    return $apiTrimmer ;
}
