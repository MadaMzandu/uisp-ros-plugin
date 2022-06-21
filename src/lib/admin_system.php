<?php

class Admin_System extends Admin
{

    public function rebuild(): bool
    {
        $command = 'php lib/admin_rebuild.php > /dev/null 2>&1 &';
        shell_exec($command);
        return true ;
    }

}