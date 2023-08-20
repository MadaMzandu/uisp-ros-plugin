<?php
include_once 'api_logger.php';
class ApiTimer
{
    private $start ;
    private $label;
    public function stop()
    {
        $in = $this->duration();
        MyLog()->Append(sprintf('%s completed in %s seconds',$this->label,$in / 1000));
        MyLog()->Append(sprintf('%s completed in %s milliseconds',$this->label,$in));
        return $in;
    }
    private function duration(){ return (microtime(true) - $this->start) * 1000; }
    public function start($label = 'task'){$this->label = $label; $this->start = microtime(true); }
    public function __construct($label = 'task'){ $this->start(); $this->label = $label; }
}