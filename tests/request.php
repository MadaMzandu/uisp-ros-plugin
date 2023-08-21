<?php
class TestRequest
{
    private ?string $_json = null;
    private ?object $_data = null ;

    public function __set($name, $value)
    {
        if(in_array($name,['e','entity'])){
            $this->data()->extraData->entity = $value;
        }
        else if(in_array($name,['b','before','prev','previous'])){
            $this->data()->extraData->entityBeforeEdit = $value ;
        }
        else{
            $this->_data->$name = $value ;
        }
    }

    public function __get($name)
    {
        if(in_array($name,['e','entity'])){
            $d = $this->data()->extraData->entity ?? null ;
        }
        else if(in_array($name,['b','before','prev','previous'])){
            $d = $this->data()->extraData->entityBeforeEdit ?? null ;
        }
        else{
            $d = $this->data()->$name ?? null ;
        }
        return is_object($d) ? new TestEntity($d) : $d ;
    }

    public function toPost(){ return json_decode(json_encode($this->_data));}

    public function toJson(){ return json_encode($this->_data,JSON_PRETTY_PRINT);}

    public function reset(){ $this->_data = null; $this->data(); }

    private function data()
    {
        if(empty($this->_data)){
            $this->_data = json_decode($this->json());
        }
        return $this->_data ?? [];
    }

    private function json()
    {
        if(empty($this->_json)){
            $this->_json = file_get_contents('../tests/test.json');
        }
        return $this->_json ;
    }
}

class TestEntity
{
    private $_data ;

    public function __get($name)
    {
        if(in_array($name,['attributes','attribute','attrs','attr','at','ats'])){
            return new TestAttrs($this->_data->attributes);}
        return $this->_data->$name ?? null ;
    }

    public function __set($name, $value)
    {
        $this->_data->$name = $value ;
    }

    public function __construct(&$data)
    {
        $this->_data =& $data ;
    }
}

class TestAttrs
{
    private $_arr ;

    public function __get($name)
    {
        for($i=0;$i < sizeof($this->_arr);$i++){
            $a =& $this->_arr[$i];
            $key = $a->key ?? null ;
            if($key == $name) return $a ;
        }
        $this->_arr[] = (object)['key' => $name];
        $i = sizeof($this->_arr) - 1;
        $r =& $this->_arr[$i] ;
        return $r ;
    }

    public function __construct(&$arr)
    {
        $this->_arr =& $arr ;
    }
}