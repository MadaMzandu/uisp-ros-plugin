<?php

class Backup extends Admin
{

    public function __construct(&$data)
    {
        parent::__construct($data);
    }

    public function autoBackup()
    {
        $file = file_get_contents('data/.last_backup');
        $date = $file
            ? explode(',', $file . ",2000-01-01 00:00:00")[1]
            : '2000-01-01 00:00:00';
        $last = new DateTime($date);
        $now = new DateTime();
        $interval = new DateInterval('P1D');
        $last->add($interval);
        if ($last < $now) {
            $this->backup();
        }
    }

    public function backup()
    {
        if (!file_exists('data/data.db')) {
            return false;
        }
        $count = 0;
        if (file_exists('data/.last_backup')) {
            $file = file_get_contents('data/.last_backup');
            $count = $file ? (int)explode(',', $file)[0] : 0; // zero if empty
        }
        $last_backup = $count > 6 ? 0 : $count; // zero on 7 counts
        $backup = 'public/backup-' . ++$last_backup;
        $main = 'data/data.db';
        //$main = new SQLite3('data/data.db');
        //$copy = new SQLite3($backup);
        if (copy($main, $backup)) {
            $now = new DateTime();
            file_put_contents('data/.last_backup',
                $last_backup . "," . $now->format('Y-m-d H:i:s'));
            $this->set_message('backup has been created');
            return true;
        }
        $this->set_error($e->getMessage());
        return false;
    }

    public function list()
    {
        $dir = 'public/';
        $list = scandir($dir);
        $this->result = [];
        foreach ($list as $item) {
            if (substr($item, 0, 6) != 'backup') {
                continue;
            }
            $this->result[$item] = [];
            $this->result[$item]['id'] = explode('-', $item)[1];
            $this->result[$item]['name'] = $item;
            $this->result[$item]['date'] = date('Y-m-d H:i:s', filemtime($dir . $item));
        }
    }

    public function restore()
    {
        $dir = 'public/';
        $name = $dir . $this->data->name;
        if (!file_exists($name)) {
            $this->set_error('backup file was not found');
            return false;
        }
        //$main = new SQLite3('data/data.db');
        //$copy = new SQLite3($name);
        if (!copy($name, 'data/data.db')) {
            $this->set_error('failed to restore backup');
            return false;
        }
        $this->set_message('backup has been restored');
        return true;
    }


}
