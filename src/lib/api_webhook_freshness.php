<?php

/** UISP's current representation is authoritative; delivery UUIDs are not source revisions. */
final class ApiWebhookFreshness
{
    private $request;
    private float $deadline;

    public function __construct(?callable $request=null,?float $deadline=null)
    { $this->request=$request; $this->deadline=$deadline??microtime(true)+8; }

    /** Pure, bounded read for previews. No cache, queue or remote mutation. */
    public function service(int $serviceId): object
    {
        $result=$this->current($serviceId,'review');
        if ($result['reason']!==null) throw new RuntimeException($result['reason']);
        if ($result['missing']) throw new RuntimeException('webhook_service_absent');
        return $result['entity'];
    }

    /** No webhook attributes, credentials or timestamps are trusted as ordering evidence. */
    public function current(int $serviceId,string $action): array
    {
        if ($serviceId<1) throw new InvalidArgumentException('webhook_fresh_state_invalid');
        try { $value=$this->get('clients/services/'.$serviceId); }
        catch (Throwable $error) {
            if ($this->notFound($error)) return ['missing'=>true,'entity'=>null,'reason'=>in_array($action,['delete','end','review'],true)?null:'webhook_service_absent'];
            return ['missing'=>false,'entity'=>null,'reason'=>'webhook_fresh_state_unavailable'];
        }
        if (!$this->valid($value,$serviceId)) return ['missing'=>false,'entity'=>null,'reason'=>'webhook_fresh_state_invalid'];
        $entity=json_decode(json_encode($value,JSON_THROW_ON_ERROR),false,64,JSON_THROW_ON_ERROR);
        $status=(int)$entity->status; $expected=['suspend'=>[3],'unsuspend'=>[1],'suspend_cancel'=>[1],'activate'=>[1],'end'=>[2,5,8],'delete'=>[2,5,8]];
        return ['missing'=>false,'entity'=>$entity,'reason'=>isset($expected[$action])&&!in_array($status,$expected[$action],true)?'webhook_state_unconfirmed':null];
    }

    private function valid($value,int $id): bool
    {
        if (!is_array($value)&&!is_object($value)) return false;
        $value=(array)$value;
        if ($this->positive($value['id']??null)!==$id || $this->positive($value['clientId']??null)===null
            || is_bool($value['status']??null) || is_float($value['status']??null)
            || filter_var($value['status']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>8]])===false
            || !array_key_exists('servicePlanId',$value) || ($value['servicePlanId']!==null&&$this->positive($value['servicePlanId'])===null)
            || !is_array($value['attributes']??null) || count($value['attributes'])>512) return false;
        $keys=[]; $ids=[];
        foreach ($value['attributes'] as $raw) {
            if (!is_array($raw)&&!is_object($raw)) return false; $attribute=(array)$raw;
            $key=$attribute['key']??null;
            if (!is_string($key)||$key===''||strlen($key)>128||isset($keys[$key])
                || !array_key_exists('value',$attribute) || ($attribute['value']!==null&&!is_scalar($attribute['value']))) return false;
            $keys[$key]=true;
            if (array_key_exists('customAttributeId',$attribute)) {
                $attributeId=$this->positive($attribute['customAttributeId']);
                if ($attributeId===null||isset($ids[$attributeId])) return false; $ids[$attributeId]=true;
            }
        }
        return true;
    }

    private function positive($value): ?int
    {
        if (is_bool($value)||is_float($value)) return null;
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); return $id===false?null:$id;
    }

    private function get(string $path)
    {
        if (microtime(true)>=$this->deadline) throw new RuntimeException('webhook_fresh_state_unavailable');
        if ($this->request!==null) $value=($this->request)($path);
        else {
            require_once __DIR__.'/api_uisp_client.php'; $sdk=ApiUispClient::crm();
            if (class_exists(\GuzzleHttp\Client::class)) {
                $property=new ReflectionProperty($sdk,'client'); if (PHP_VERSION_ID<80100) $property->setAccessible(true);
                $client=$property->getValue($sdk); if (!$client instanceof \GuzzleHttp\Client) throw new RuntimeException('webhook_fresh_state_unavailable');
                $remaining=max(0.1,$this->deadline-microtime(true)); $options=$client->getConfig();
                $options['timeout']=min(5,$remaining); $options['connect_timeout']=min(2,$remaining); $options['read_timeout']=min(5,$remaining);
                $property->setValue($sdk,new \GuzzleHttp\Client($options));
            }
            $value=$sdk->get($path,[]);
        }
        if (microtime(true)>=$this->deadline) throw new RuntimeException('webhook_fresh_state_unavailable');
        return $value;
    }

    private function notFound(Throwable $error): bool
    {
        for ($current=$error;$current!==null;$current=$current->getPrevious()) {
            if ($current instanceof \GuzzleHttp\Exception\RequestException && $current->hasResponse()) return $current->getResponse()->getStatusCode()===404;
        }
        return false;
    }

    /** One service per scheduler pass; only its ID is retained, never its earlier action or body. */
    public static function tick(float $budgetSeconds=8): array
    { return (new self(null,microtime(true)+max(1,min(12,$budgetSeconds))))->drain(); }

    public function drain(?ApiJobQueue $queue=null): array
    {
        require_once __DIR__.'/api_job_queue.php'; $queue=$queue??myJobQueue(); $row=$queue->nextFreshReview();
        if ($row===null) return ['attempted'=>0,'queued'=>0,'pending'=>0,'reason'=>null];
        $sid=(int)$row['service_id'];
        try {
            require_once __DIR__.'/api_action.php';
            $action=new ApiAction(null,$this); $result=$action->reconcileService($sid,true);
            if (!$action->status()->error) return ['attempted'=>1,'queued'=>count($result['jobKeys']??[]),'pending'=>0,'service_id'=>$sid,'reason'=>null];
            $reason=$result['reason']??'webhook_fresh_state_unavailable';
        } catch (Throwable $ignored) { $reason='webhook_fresh_state_unavailable'; }
        $queue->backoffFreshReview($sid,$reason);
        return ['attempted'=>1,'queued'=>0,'pending'=>1,'service_id'=>$sid,'reason'=>$reason];
    }
}
