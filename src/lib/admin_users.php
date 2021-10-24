<?php

class Users extends Admin {

    public function login() {
        $this->status->session = 'none';
        if (!$this->read()) {
            $this->set_error('The username or password provided is not valid');
            return false;
        }
        if (!password_verify($this->data->password, $this->user['password'])) {
            $this->set_error('The username or password provided is not valid');
            return false;
        }
        return $this->createSession();
    }

    public function authenticate() {
        if (!$this->read()) {
            $this->set_error('this session was not found.please login again');
            return false;
        }
        if (!$this->checkSession()) {
            $this->set_error('this session has expired. please login again');
            return false;
        }
        $this->result = (object) $this->user;
        $this->status->authenticated = true;
        return true;
    }

    public function password() {
        if (!$this->read()) {
            $this->set_error('unable to find this user');
            return false;
        }
        if (!password_verify($this->data->password, $this->user['password'])) {
            $this->set_error('the password provided is not valid');
            return false;
        }
        $data = (object) [
                    'id' => $this->user['id'],
                    'password' => password_hash($this->data->newPassword, 1),
        ];
        $db = new CS_SQLite();
        if ($db->edit($data, 'users')) {
            $this->set_message('password for ' . $this->user['username'] . ' has been changed');
            return true;
        }
        $this->set_error('failed to change the password');
        return false;
    }

    public function insert() {
        $db = new CS_SQLite();
        if (!$this->checkUsername()) {
            return false;
        }
        $this->hashPassword();
        if (!$db->insert($this->data, 'users')) {
            $this->set_error('failed to add the new user');
            return false;
        }
        $this->set_message('the new user has been added');
        return true;
    }

    private function checkUsername() {
        $db = new CS_SQLite();
        if ($db->ifUsernameExists($this->data->username)) {
            $this->set_error('the username provided already exists');
            return false;
        }
        return true;
    }

    private function hashPassword() {
        $this->data->password = password_hash($this->data->password, 1);
    }

    private function checkSession() {
        global $conf;
        $timeout = $conf->ui_timeout;
        $now = new DateTime();
        $last = new DateTime($this->user['last']);
        $interval = new DateInterval('PT' . $timeout . 'M');
        $last->add($interval);
        if ($last > $now) {
            return true;
        }
        return false;
    }

    private function read() {
        $db = new CS_SQLite();
        if (is_object($this->data)) {
            if (property_exists($this->data, 'session')) {
                $this->user = $db->selectUserBySession($this->data->session) ?? [];
            } elseif (property_exists($this->data, 'username')) {
                $this->user = $db->selectUserByUsername($this->data->username) ?? [];
            }
        }
        return $this->user ? true : false;
    }

    private function createSession() {
        if ($this->checkSession()) {
            $this->status->session = $this->user['session'];
            return true;
        }
        $this->status->session = (new DateTime())->format('dYmHsi') . rand(100, 999);
        if ($this->updateSession()) {
            return true;
        }
        $this->set_error('failed to create user session');
        error_log(json_encode($this->status));
        return false;
    }

    private function updateSession() {
        $db = new CS_SQLite();
        $data = (object) [
                    'id' => $this->user['id'],
                    'session' => $this->status->session,
        ];
        return $db->edit($data, 'users');
    }

}
