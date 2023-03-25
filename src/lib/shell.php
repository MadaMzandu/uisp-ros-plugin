#!/usr/bin/php
<?php
include_once 'lib/admin_cache.php';
include_once 'lib/admin_rebuild.php';
include_once 'lib/api_jobs.php';

function jobs()
{
    (new Api_Jobs())->run();
}

function cache()
{
    (new Admin_Cache())->create();
}

function rebuild()
{
    (new AdminRebuild())->rebuild([]);
}

if($argc < 2 ) exit();
$action = $argv[1] ?? null;
if(!is_string($action)) exit();
switch(strtolower($action)){
    case 'cache': cache();break ;
    case 'rebuild': rebuild();break;
    case 'jobs' : jobs();break;
    default: exit();
}

