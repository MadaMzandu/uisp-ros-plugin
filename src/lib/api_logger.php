<?php
require_once  'vendor/autoload.php';

const API_DEBUG_ECHO = 0;

class ApiLogger
{
    public function Append($entry, $level = LOG_DEBUG)
    {
        if (!$this->Debug() && $level >= LOG_DEBUG){ return; }
        if(is_array($entry)){ $entry = json_encode($entry); }
        error_log(sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL,3,'data/plugin.log');
        if(API_DEBUG_ECHO > 0) echo sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL;
    }

    private function Debug(): bool
    {
        $fn = 'data/config.json';
        if(!is_file($fn)){ return '0'; }
        $file = file_get_contents($fn) ?? '{}';
        $conf = json_decode($file);
        return $conf->debugEnable ?? '0';
    }

    private function ULog()
    {
        $l = \Ubnt\UcrmPluginSdk\Service\PluginLogManager::create();
        return $l;
    }

    private function Time()
    {
        return (new DateTime())->format('Y-m-d H:i:s.v');
    }
}

function MyLog(){
    return new ApiLogger();
}