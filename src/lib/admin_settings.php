<?php

class Settings extends Admin
{

    public function edit(): bool
    {
        if ($this->db()->saveConfig($this->data)) {
            $this->set_message('configuration has been updated');
            return true;
        }
        $this->set_error('failed to update configuration');
        return false;
    }


    public function get(): bool
    {
        $this->read = $this->db()->readConfig();
        if (!$this->read) {
            $this->set_error('failed to read settings');
            return false;
        }
        $this->result = $this->read;
        return true;
    }

}
