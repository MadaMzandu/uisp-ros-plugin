<?php

const API_DEBUG_ECHO = 0;

class ApiLogger
{
    // Keep debug separate from informational records on platforms whose syslog
    // constants alias LOG_DEBUG to LOG_INFO (notably the Windows PHP runtime).
    public const DEBUG = 7;

    public function Append($entry, $level = self::DEBUG)
    {
        if (!$this->Debug() && $level >= self::DEBUG){ return; }
        $fn = 'data/plugin.log';
        if(is_array($entry) || is_object($entry)){ $entry = json_encode($this->redact((array) $entry)); }
        else {
            $decoded = json_decode((string) $entry, true);
            if (is_array($decoded)) $entry = json_encode($this->redact($decoded));
            $entry = preg_replace('/((?:password|routerPass|webhookToken|webhookSecret|unmsToken|pluginAppKey)["\s:=]+)[^\s,}\"]+/i', '$1[redacted]', (string) $entry);
        }
        @error_log(sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL,3,$fn);
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

    private function redact(array $data): array
    {
        if (isset($data['key']) && preg_match('/password|routerPass|webhookToken|webhookSecret|unmsToken|pluginAppKey/i', (string) $data['key']) && array_key_exists('value', $data)) $data['value'] = '[redacted]';
        foreach ($data as $key => &$value) {
            if (preg_match('/password|routerPass|webhookToken|webhookSecret|unmsToken|pluginAppKey|authorization/i', (string) $key)) $value = '[redacted]';
            elseif (is_array($value) || is_object($value)) $value = $this->redact((array) $value);
            elseif (is_string($value)) {
                $json = json_decode($value, true);
                if (is_array($json)) $value = json_encode($this->redact($json));
            }
        }
        return $data;
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
