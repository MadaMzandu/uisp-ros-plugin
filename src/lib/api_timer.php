<?php
include_once 'api_logger.php';
class ApiTimer
{
    private $start ;
    private $action;
    public function stop()
    {
        $in = $this->duration();
        MyLog()->Append(sprintf('%s completed in %s milliseconds',$this->action,$in));
        return $in;
    }
    private function duration(){ return (microtime(true) - $this->start) * 1000 ; }
    public function start(){ $this->start = microtime(true); }
    public function __construct($action = 'task'){ $this->start(); $this->action = $action; }
}