<?php

class Stats extends Admin
{

    private $devices;

    public function get()
    {

        $this->countDevices();
        $this->countOffline();
        $this->countServices();
    }

    private function countDevices()
    {
        $data = (object)['session' => $this->status->session];
        $d = new Devices($data);
        $d->get();
        $this->devices = $d->result();
        $this->result->devices = sizeof((array)$this->devices);
    }

    private function countOffline()
    {
        $count = 0;
        $code = 0;
        $err = '';
        foreach ($this->devices as $device) {
            $conn = @fsockopen($device['ip'],
                $this->defaultPort($device['type']),
                $code, $err, 0.2);
            if (!is_resource($conn)) {
                $count++;
                continue;
            }
            fclose($conn);
        }
        $this->result->offline = $count;
    }

    private function defaultPort($type)
    {
        $ports = array(
            'mikrotik' => 8728,
            'cisco' => 22,
            'radius' => 3301,
        );
        return $ports[$type];
    }

    private function countServices()
    {
        $db = new API_SQLite();
        $this->result->active = $db->countServices();
        $this->result->suspended = $db->countSuspendedServices();
    }

}
