<?php
include_once 'api_sqlite.php';

class Service_Base
{

    public $ready;
    public $mode = 0;
    public $type = 'service';
    protected $status;
    protected $data;
    protected $entity;
    protected $before;
    protected $conf;

    public function __construct($data)
    {
        $this->data = $this->toObject($data);
        $this->init();
    }

    public function queued(): bool
    {
        return $this->data->queued ?? false ;
    }

    private function toObject($data)
    {
        if(empty($data)) return null ;
        if (is_array($data) || is_object($data)) {
            return is_object($data) ? $data
                : json_decode(json_encode($data));
        }
        return null;
    }

    protected function init(): void
    {
        $this->ready = true;
        $this->status = (object)[];
        $this->status->ready = &$this->ready;
        $this->status->error = false;
        $this->status->message = 'ok';
        $this->get_config();
        $this->fix_attributes();
        $this->set_shortcuts();
    }

    public function exists(): bool
    {
        return (bool)$this->db()
            ->ifServiceIdExists($this->entity->id);
    }

    public function mode($mode = null): ?int
    {//switches between edit and before edit objects
        // or returns mode is parameter is null
        $edit = $this->data->extraData->entityBeforeEdit ?? null;
        if (is_int($mode)) {
            if($mode > 1) $mode = 1 ;
            if(empty($edit)) $mode =  0 ;
            $this->mode = $mode;
            $this->plan->mode = $mode;
            $this->client->mode = $mode;
            return null;
        }
        return $this->mode;
    }

    protected function set_shortcuts()
    {
        $this->entity = $this->data->extraData->entity ?? (object)[];
        $this->before = $this->data->extraData->entityBeforeEdit ?? (object)[];
    }

    public function queue_job($status=[]): void
    {
        if ($this->queued()) { //already queued
            return;
        }
        $file = 'data/queue.json';
        $q = [];
        if (file_exists($file)) {
            $f = file_get_contents($file) ?? "[]";
            $q = json_decode($f, true);
        }
        $id = $this->data->entityId ?? 0;
        if ($id) {
            $q[$id] = [
                'data' => $this->data,
                'status' => $status,
                'last' => date('Y-m-d H:i:s'),
            ];
            file_put_contents($file, json_encode($q));
        }
    }

    protected function get_config()
    {
        $this->conf = $this->db()->readConfig();
        if (!(array)$this->conf) {
            $this->setErr('failed to read plugin configuration');
        }
    }

    protected function get_value($key, $entity = null ): ?string
    { //returns an attribute value
        if(!$entity) {
            $entity = $this->mode ? 'before' : 'entity';
        }
       return $this->$entity->attributes[$key]->value ?? null ;
    }

    protected function set_attribute($attribute, $value): bool
    {
        $attribute = $this->list_attribute($attribute);
        $data = ['attributes' => [['customAttributeId' => $attribute->id, 'value' => $value]]];
        $id = $this->entity->id;
        return (bool)(new API_Unms())->request('clients/services/' . $id, 'PATCH', $data);
    }

    protected function fix_attributes()
    {
        $objects = ['entity','entityBeforeEdit'];
        foreach($objects as $object){
            $attrs =  $this->data->extraData->$object->attributes ?? [];
            $fixed = [] ;
            foreach ($attrs as $attr){
                $fixed[$attr->key] = $attr ;
            }
            $this->data->extraData->$object->attributes = $fixed;
        }
    }

    protected function list_attribute($attribute): ?stdClass
    {
        $list = (new API_Unms())->request('custom-attributes');
        foreach ($list as $item) {
            if ($item->key == $attribute) {
                return $item;
            }
        }
        return null;
    }

    protected function db(): ?API_SQLite
    {
        try {
            return new API_SQLite();
        } catch (Exception $e) {
            $this->setErr($e->getMessage());
            return null;
        }
    }

    protected function setErr($err)
    {
        $this->ready = false;
        $this->status->error = true;
        $this->status->message = $err;
        return null;
    }

    public function status()
    {
        return $this->status;
    }

}