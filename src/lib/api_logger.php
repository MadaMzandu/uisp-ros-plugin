<?php
require_once  'vendor/autoload.php';

const API_DEBUG_LEVEL = 7;

class ApiLogger
{
    public function Append($entry, $level = LOG_DEBUG)
    {
        if ($level > API_DEBUG_LEVEL) return;
//        error_log(sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL,3,'data/plugin.log');
//      echo sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL;
       $this->ULog()->appendLog(sprintf("%s: %s",$this->Time(), $entry));
    }

    private function Debug(): bool
    {
        $cm = \Ubnt\UcrmPluginSdk\Service\PluginConfigManager::create();
        return (bool)$cm->loadConfig()['debugEnable'];
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