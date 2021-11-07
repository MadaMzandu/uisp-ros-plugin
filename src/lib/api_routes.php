<?php

include_once 'mt_account.php';

class API_Routes
{

    private $status;
    private $service;
    private $module;

    public function __construct($service)
    {
        $this->service = $service;
        $this->status = (object)[
            'status' => 'ok',
            'message' => '',
            'error' => false,
        ];
        $this->exec();
    }

    private function exec(): void
    {
        $module = $this->select_device();
        if (!$module) {
            $this->status->error = true;
            $this->status->message = 'Could not find module for provided device';
            return;
        }
        $this->module = new $module($this->service);
        $action = $this->service->action;
        $this->module->$action();
        $this->status = $this->module->status();
    }

    private function select_device(): ?string
    {
        $map = [
            'radius' => 'Radius_Account',
            'mikrotik' => 'MT_Account',
        ];
        $type = $this->service->device()->type;
        return $type ? $map[$type] : null;
    }

    public function status(): ?stdClass
    {
        return $this->status;
    }


}
