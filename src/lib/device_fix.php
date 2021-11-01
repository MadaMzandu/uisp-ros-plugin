<?php

include_once 'device_account.php';

class Device_Fix extends Device_Account{
    private $request_obj;

    protected function fix():bool {
        $this->load_object();
        if(!$this->request_obj){
            $this->set_error('failed to create service request object');
            return false;
        }
        $ret = $this->apply_fix();
        return (bool) $ret;
    }

    private function apply_fix() {
        global $conf;
        $clientId = $this->entity->clientId;
        $id = $this->entity->id;
        $u = new CS_UISP();
        if ($u->request('/clients/services/' . $id . '/end', 'PATCH')) {//end service
            $u->request('/clients/services/' . $id, 'DELETE'); //delete service
            sleep($conf->unsuspend_fix_wait);
            return $u->request('/clients/' . $clientId . '/services',
                'POST', $this->request_obj); //recreate service
        }
        return false;
    }

    private function load_object():void{
        $this->request_obj = json_decode(
            json_encode($this->entity),true) ;
        $this->trim_object();
        $this->trim_attributes();
    }

    private function trim_attributes():void {
        $keys = ["id", "serviceId", "name", "key", "clientZoneVisible"];
        $e = sizeof($this->request_obj['attributes']);
        for($i=0;$i<$e;$i++) {
            foreach($keys as $key){
                unset($this->request_obj['attributes'][$i][$key]);
            }
        }
    }

    private function trim_object() :void{
        $valid_keys =  "name,fullAddress,street1,street2,city,countryId,"
            . "stateId,zipCode,note,addressGpsLat,addressGpsLon,"
            . "servicePlanPeriodId,price,invoiceLabel,contractId,"
            . "contractLengthType,minimumContractLengthMonths,activeFrom,"
            . "activeTo,contractEndDate,discountType,discountValue,"
            . "discountInvoiceLabel,discountFrom,discountTo,tax1Id,tax2Id,"
            . "tax3Id,invoicingStart,invoicingPeriodType,"
            . "invoicingPeriodStartDay,nextInvoicingDayAdjustment,"
            . "invoicingProratedSeparately,invoicingSeparately,"
            . "sendEmailsAutomatically,useCreditAutomatically,"
            . "servicePlanPeriod,fccBlockId,attributes,addressData,"
            . "setupFeePrice,earlyTerminationFeePrice";
        $temp = [];
        $keys = explode(',', $valid_keys);
        foreach ($keys as $key) {
            $temp[$key] = $this->request_obj[$key];
        }
        $this->request_obj = $temp ;
    }

}