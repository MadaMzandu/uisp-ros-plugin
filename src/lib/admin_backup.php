<?php

class Admin_Backup extends Admin
{

    public function run()
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
        $backup = 'data/backup-' . ++$last_backup;
        $main = 'data/data.db';
        if (copy($main, $backup)) {
            $now = new DateTime();
            file_put_contents('data/.last_backup',
                $last_backup . "," . $now->format('Y-m-d H:i:s'));
            $this->set_message('backup has been created');
            return true;
        }
        return false;
    }

    public function list(): void
    {
        $dir = 'data/';
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

    public function restore(): bool
    {
        $dir = 'data/';
        $name = $dir . $this->data->name;
        if (!file_exists($name)) {
            $this->set_error('backup file was not found');
            return false;
        }
        if (!copy($name, 'data/data.db')) {
            $this->set_error('failed to restore backup');
            return false;
        }
        $this->set_message('backup has been restored');
        return true;
    }

    public function publish(): bool
    {
        $src = 'data/'. $this->data->name;
        $dst = 'public/'.$this->data->name;
        $copy = copy($src,$dst);
        if(!$copy){
            $this->set_error('failed to publish file for download');
        }
        return $copy;
    }

    public function unpublish(): bool
    {
        $file = 'public/'.$this->data->name;
        $del = unlink($file);
        if(!$del){
            $this->set_error('failed to unpublish backup file');
        }
        return $del ;
    }


}
