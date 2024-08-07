<?php

const API_DEBUG_ECHO = 0;

class ApiLogger
{
    public function Append($entry, $level = LOG_DEBUG)
    {
        if (!$this->Debug() && $level >= LOG_DEBUG){ return; }
        $fn = 'data/plugin.log';
        if(is_array($entry) || is_object($entry)){ $entry = json_encode($entry); }
        error_log(sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL,3,$fn);
        if(API_DEBUG_ECHO) echo sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL;
    }

    private function Debug(): bool
    {
//        return '1'; //testing
        $fn = 'data/config.json';
        if(!is_file($fn)){ return false ;}
        $conf = json_decode(file_get_contents($fn));
        if(is_object($conf)){
            return $conf->debugEnable ?? false ;
        }
        return false;
    }

    private function Time(): string
    {
        return date('Y-m-d H:i:s.v');
    }
}

$apiLogger = null ;

function MyLog(): ApiLogger
{
    global $apiLogger;
    if(empty($apiLogger)){
        $apiLogger = new ApiLogger();
    }
    return $apiLogger ;
}