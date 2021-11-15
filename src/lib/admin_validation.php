<?php

// a very lazy effort since we only have two object classes to validate
// will probably move these to their respective object classes

class Validation extends Admin
{

    private $keys;
    private $subnet;

    public function __construct(&$data)
    {
        parent::__construct($data);
        $this->keys = array_keys((array)$this->data);
        $this->setValidationFields();
    }

    private function setValidationFields()
    {
        $newDevice = [];
        $keys = array_keys((array)$this->data);
        foreach ($keys as $key) {
            $newDevice[$key] = [
                'value' => $this->data->{$key} ?? '',
                'error' => false,
                'message' => '',
            ];
        }
        $this->result = $newDevice;
    }

    public function validate()
    {

        $checks = ['checkEmpty', 'checkName', 'checkIpAddress', 'checkIpPool', 'checkExclusions',
            'checkUisp', 'checkToken', 'checkFixWait'];
        foreach ($checks as $check) {
            if (!$this->{$check}()) {
                return;
            }
        }
    }

    private function checkFixWait()
    {
        $field = 'unsuspend_fix_wait';
        if (!in_array($field, $this->keys)) {
            return true;
        }
        if ($this->data->{$field} < 5) {
            $this->setFieldError($field, 'this value should not be less than 5 seconds');
            $this->set_error('failed: unsuspend fix wait too low');
            return false;
        }
        return true;
    }

    private function setFieldError($field, $message)
    {
        $object = [
            'value' => $this->data->{$field},
            'error' => true,
            'message' => $message,
        ];
        $this->result[$field] = $object;
    }

    private function checkName()
    {
        $field = 'name';
        if (!in_array($field, $this->keys)) {
            return true;
        }
        $db = new API_SQLite();
        if ($this->data->id > 0) {
            $device = $db->selectDeviceById($this->data->id);
            if (strtolower($device->name) == strtolower($this->data->name)) {
                return true;
            }
        }
        if ($db->ifDeviceNameIsUsed($this->data->{$field})) {
            $this->setFieldError($field, 'this device name has already been defined');
            $this->set_error('failed: name not unique');
            return false;
        }
        return true;
    }

    private function checkUisp()
    {
        $field = 'uisp_url';
        if (!in_array($field, $this->keys)) {
            return true;
        }
        if (!$this->checkServerStatus()) {
            $this->setFieldError($field, 'uisp url is not responding');
            $this->set_error('failed: server not responding');
            return false;
        }
        return true;
    }

    private function checkServerStatus()
    {
        $h0 = explode('://', $this->data->uisp_url)[1];
        $h1 = explode('/', $h0)[0];
        $h1 .= ':';
        [$host, $p0] = explode(':', $h1);
        $port = 443;
        if ($p0) {
            $port = (int)$p0;
        }
        $conn = @fsockopen($host,
            $port,
            $code, $err, 0.3);
        if (!is_resource($conn)) {
            return false;
        }
        fclose($conn);
        return true;
    }

    private function checkToken()
    {
        $field = 'uisp_token';
        if (!in_array($field, $this->keys)) {
            return true;
        }
        global $conf;
        $savedToken = $conf->{$field};
        $conf->{$field} = $this->data->{$field};
        $u = new API_Unms();
        $test = $u->request('/service-plans');
        if (!$test) {
            $this->setFieldError($field, 'token may be invalid - services plans not found using token');
            $this->set_error('could not access service plans with token');
            $conf->{$field} = $savedToken;
            return false;
        }
        $conf->{$field} = $savedToken;
        return true;
    }

    private function checkEmpty()
    {
        $fields = ['name', 'ip', 'user', 'uisp_url', 'uisp_token', 'disabled_list',
            'disabled_profile', 'pppoe_user_attr', 'pppoe_pass_attr', 'device_name_attr',
            'mac_addr_attr', 'ip_addr_attr'
        ];
        foreach ($fields as $field) {
            if (!in_array($field, $this->keys)) {
                continue;
            }
            if (empty($this->data->{$field})) {
                $this->setFieldError($field, 'this field needs to be defined');
                $this->set_error('failed: required field undefined');
                return false;
            }
        }
        return true;
    }

    private function checkExclusions()
    {
        $field = 'excl_addr';
        if (!in_array($field, $this->keys) || empty($this->data->{$field})) {
            return true;
        }
        return $this->iterateExclusions();
    }

    private function iterateExclusions()
    {
        $field = 'excl_addr';
        $ranges = explode(',', $this->data->{$field});
        foreach ($ranges as $range) {
            $range .= '-';
            [$start, $end] = explode('-', $range);
            if (!$end) {
                $end = $start;
            }
            if ((!filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ||
                (!filter_var($end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
                $this->setFieldError($field, 'exclusions must contain valid ipv4 address ranges');
                $this->set_error('failed:ip exclusion validation');
                return false;
            }
            if (ip2long($end) < ip2long($start)) {
                $this->setFieldError($field, 'end of range cannot be lower than start of range');
                $this->set_error('failed:ip exclusion validation');
                return false;
            }
        }
        return true;
    }

    private function checkIpPool()
    {
        $fields = ['pool', 'ppp_pool',];
        foreach ($fields as $field) {
            if (!in_array($field, $this->keys) || empty($this->data->{$field})) {
                continue;
            }
            $entries = [];
            if (is_string($this->data->{$field})) {
                $entries = explode(',', $this->data->{$field});
            } else {
                $entries = $this->data->{$field};
            }
            return $this->iteratePool($field, $entries);
        }
        return true;
    }

    private function iteratePool($field, $entries)
    {
        foreach ($entries as $entry) {
            $entry .= '/';
            [$prefix, $mask] = explode('/', $entry);
            if (!filter_var($prefix, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $this->setFieldError($field, 'please enter a valid ipv4 prefix');
                $this->set_error('failed:ip prefix validation');
                return false;
            }
            if ($mask < 1 || $mask > 32) {
                $this->setFieldError($field, 'ipv4 prefix length should be between /1 to /32');
                $this->set_error('failed:ip prefix length validation');
                return false;
            }
            if (!$this->netIsValid($prefix, $mask)) {
                $this->setFieldError($field, 'the correct subnet address should be ' . $this->subnet . '/' . $mask);
                $this->set_error('failed:ip prefix length validation');
                return false;
            }
        }
        return true;
    }

    private function netIsValid($prefix, $len)
    {
        $this->subnet = long2ip(ip2long($prefix) & (-1 << (32 - $len)));
        return $this->subnet == $prefix ? true : false;
    }

    private function checkIpAddress()
    {
        $fields = ['ip'];
        foreach ($fields as $field) {
            if (!in_array($field, $this->keys)) {
                continue;
            }
            if (!filter_var($this->data->{$field}, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $this->setFieldError($field, 'this ip address is not valid');
                $this->set_error('failed:ip validation');
                return false;
            }
        }
        return true;
    }

}
