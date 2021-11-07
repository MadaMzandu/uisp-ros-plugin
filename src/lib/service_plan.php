<?php

include_once 'service_attributes.php';

class Service_Plan extends Service_Attributes
{

    public $contention;
    protected $plan;

    public function plan_name()
    {
        return $this->entity->servicePlanName;
    }

    public function plan_id()
    {
        return $this->entity->servicePlanId;
    }

    public function plan_rate()
    {
        $shares = $this->shares();
        $u = $this->rate()->upload * $shares;
        $d = $this->rate()->download * $shares;
        return (object)[
            'text' => $u . 'M/' . $d . 'M',
            'upload' => $u,
            'download' => $d,
        ];
    }

    protected function shares()
    { // calculates the number of contention shares
        $ratio = $this->get_plan()['ratio'];
        $children = $this->plan_children();
        $shares = intdiv($children, $ratio);
        return ($children % $ratio) > 0 ? ++$shares : $shares; // go figure :-)
    }

    protected function get_plan()
    {
        if ($this->plan) {
            return $this->plan;
        }
        $entity = $this->move ? 'before' : 'entity';
        $planId = $this->$entity->servicePlanId;
        $this->plan = (new Plans($planId))->list()[$planId] ?? [];
        return $this->plan;
    }

    public function plan_children()
    {
        $entity = $this->move ? 'before' : 'entity';
        $device = $this->$entity->{$this->conf->device_name_attr};
        $planId = $this->$entity->servicePlanId;
        $deviceId = $this->db()->selectDeviceIdByDeviceName($device);
        $children = $this->db()->countDeviceServicesByPlanId($planId, $deviceId);
        $children += $this->contention;
        return max($children, 0);
    }

    public function rate()
    {
        $d = $this->entity->downloadSpeed;
        $u = $this->entity->uploadSpeed;
        return (object)[
            'text' => $u . 'M/' . $d . "M",
            'download' => $d,
            'upload' => $u,
        ];
    }

}