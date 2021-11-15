<?php

include_once 'device_account.php';

class Device_Fix extends Device_Account
{

    private $request_obj;


    public function date_fix()
    {
        $this->load_object();
        if (!$this->request_obj) {
            $this->setErr('failed to create service request object');
            return false;
        }
        return $this->apply_fix();
    }

    private function load_object()
    {
        $this->request_obj = json_decode(
            json_encode($this->svc->entity()), true);
        $this->trim_object();
        $this->trim_attrbs();
    }

    private function trim_object()
    {
        $valid_keys = "name,fullAddress,street1,street2,city,countryId,"
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
        $this->request_obj = $temp;
    }

    private function trim_attrbs()
    {
        $keys = ["id", "serviceId", "name", "key", "clientZoneVisible"];
        $e = sizeof($this->request_obj['attributes']);
        for ($i = 0; $i < $e; $i++) {
            foreach ($keys as $key) {
                unset($this->request_obj['attributes'][$i][$key]);
            }
        }
    }

    private function apply_fix(): array
    {
        $clientId = $this->svc->client->id();
        $id = $this->svc->id();
        $u = new API_Unms();
        $u->assoc = true ;
        if ($u->request('/clients/services/' . $id . '/end', 'PATCH')) {//end service
            $u->request('/clients/services/' . $id, 'DELETE'); //delete service
            sleep($this->conf->unsuspend_fix_wait);
            return $u->request('/clients/' . $clientId . '/services',
                'POST', $this->request_obj); //recreate service
        }
        return [];
    }

}
