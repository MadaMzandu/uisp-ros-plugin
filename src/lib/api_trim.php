<?php
include_once 'api_attributes.php';
include_once 'api_job_queue.php';

class ApiTrim
{// trim request to min and map to db fields

    public function trim($type, $request): ?array
    {
        switch (strtolower($type)) {
            case 'service':
            case 'services': return $this->trim_service($request);
            case 'client':
            case 'clients': return $this->trim_client($request);
            case 'site':
            case 'sites': return $this->trim_site($request);
            default: return null;
        }
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
        if (property_exists($item, 'companyName')) { $entity['company'] = $item->companyName; }
        return ['entity' => $entity];
    }

    private function trim_service($request): ?array
    {
        $entity = $request->extraData->entity ?? $request;
        $previous = $request->extraData->entityBeforeEdit ?? null ;
        $return['action'] = $request->changeType ?? 'insert' ;
        $return['entity'] = $this->extract($entity);
        if($previous){
            try { $return['previous'] = $this->extract($previous); }
            catch (InvalidArgumentException $error) {
                if ($error->getMessage() !== 'service_site_not_configured') throw $error;
                // A router may have been renamed or removed since the event's old
                // attributes were recorded. Only confirmed applied state can replace
                // that obsolete reference; never guess an endpoint from the label.
                $id = filter_var($entity->id ?? $request->entityId ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $oldId = filter_var($previous->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || $oldId !== $id) throw $error;
                $applied = myJobQueue()->appliedSnapshot($id);
                if (!$this->usableAppliedIdentity($applied, $id)) throw $error;
                // Internal frozen commands/configuration belong to the old job, not
                // to the desired service that ApiAction merges with these fields.
                $fields = 'id,clientId,status,uploadSpeed,downloadSpeed,price,totalPrice,currencyCode,'
                    . 'planId,device,username,password,mac,hotspot,duid,iaid,callerId,network';
                $return['previous'] = array_intersect_key($applied, array_fill_keys(explode(',', $fields), true));
                foreach (['address', 'address6'] as $field) {
                    if (isset($applied[$field]) && $applied[$field] !== '') $return['previous']['network'][$field] = $applied[$field];
                }
            }
        }
        return $return;
    }

    private function usableAppliedIdentity(array $applied, int $id): bool
    {
        if ((int) ($applied['id'] ?? 0) !== $id || (int) ($applied['device'] ?? 0) < 1
            || empty($applied['_commands']) || !is_array($applied['_commands'])
            || empty($applied['_device']['ip'])) return false;
        foreach (['username', 'mac'] as $field) {
            if (isset($applied[$field]) && $applied[$field] !== '') return true;
        }
        return false;
    }

    private function extract($item): array
    {
        // An omitted property is a partial update; an explicit empty list clears attributes.
        $entity = property_exists($item, 'attributes') ? $this->attrs()->extract($item->attributes ?? []) : [];
        $fields = "id,clientId,status,uploadSpeed,".
            "downloadSpeed,price,totalPrice,currencyCode";
        $array = json_decode(json_encode($item),true);
        $trim = array_intersect_key($array,
            array_fill_keys(explode(',',$fields),null));
        $entity = array_replace($entity,$trim);
        if (property_exists($item, 'servicePlanId')) { $entity['planId'] = $item->servicePlanId; }
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
