<?php

class Api_Lang extends Admin
{
    protected $lang ;
    protected $file ;
    protected $files ;
    protected $type ;

    protected function init(): void
    {
        parent::init();
        $default = 'en-USA';
        $files = ['messages','fields'];
        $accept = ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? $default) .',' ; //add trailing comma
        $lang = explode('-',explode(',',$accept)[0] . '-')[0];
        foreach ($files as $file){
            $path = 'includes/l10n/';
            $path .= $file . '_' . strtolower($lang) . ".json";
            if(!file_exists($path)){
                throw new Exception($file . ' language file not found');
            }
            $this->files[] = $path ;
        }
    }

    public function get(): void
    {
        $this->result = [];
        foreach ($this->files as $file){
            $read = json_decode(file_get_contents($file));
            $this->result[] = $read;
        }
    }

}
