<?php

class Test{
    private $data ;
    private $id ;
    private $entity ;
    private $before ;
    
    public function __construct() {
        $this->data = json_decode(file_get_contents('json/test.json'));
        $this->entity = &$this->data->extraData->entity ;
        $this->before = &$this->data->extraData->entityBeforeEdit ;
        $this->id = &$this->entity->id ;
        $this->init();
    }
    
    private function clear_db(){
        $db = new API_SQLite();
        $db->delete($this->id);
    }
    
    private function clear_router(){
        
    }

}