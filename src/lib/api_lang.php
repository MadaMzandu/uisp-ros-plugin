<?php

class Api_Lang extends Admin
{
    protected $lang ;
    protected $file ;
    protected $type ;

    protected function init(): void
    {
        parent::init();
        $default = 'includes/l10n/fields_en.json';
        $lang = strtolower($this->data->lang ?? 'en') ;
        $type = strtolower($this->data->type ?? 'fields') ;
        $file = 'includes/l10n/' . $type . '_' . $lang . '.json';
        $this->file = file_exists($file) ? $file : $default ;
        if(!file_exists($this->file)){
            throw new Exception('language file not found');
        }
    }

    public function get(): void
    {
        $read = json_decode(
            file_get_contents($this->file)
        ) ;
        $this->result = $read ?  : null ;
    }


}
