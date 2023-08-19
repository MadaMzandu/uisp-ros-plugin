<?php

class ApiLang extends Admin
{
    protected $lang ;
    protected $file ;
    protected $files ;
    protected $type ;

    protected function init(): void
    {
        parent::init();
        $defaultAccept = 'en-USA';
        $defaultLang = 'en';
        $files = ['messages','fields'];
        $accept = ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? $defaultAccept) .',' ; //add trailing comma
        $lang = explode('-',explode(',',$accept)[0] . '-')[0];
        foreach ($files as $file){
            $dir = 'includes/l10n/';
            $path = $dir . $file . '_' . strtolower($lang) . ".json";
            if(!file_exists($path)) $path = $dir . $file . "_" . $defaultLang . ".json";
            $this->files[] = $path ;
        }
    }

    public function get(): void
    {
        $this->result = [];
        foreach ($this->files as $file){
            $read = json_decode(file_get_contents($file));
            $this->result[] = $read ;
        }
    }

}
