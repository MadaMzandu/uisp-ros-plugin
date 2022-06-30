#!/usr/bin/php
<?php
/*
this is a repair script that can be used when the ros plugin fails to load after an update
*/

$update = 'DROP TABLE IF EXISTS "devtmp" ; DROP TABLE IF EXISTS "svctmp" ; CREATE TABLE IF NOT EXISTS "svctmp" (     "id"       INT,     "device"   INT,     "address"  TEXT,     "prefix6"   TEXT,     "clientId" INT,     "planId"   INT,     "status"   INT,     "last"   TEXT,     "created"  TEXT ); CREATE TABLE  IF NOT EXISTS "devtmp" (      "id"    INTEGER NOT NULL,      "name"  TEXT,      "ip"    TEXT,      "type"  TEXT,      "user"  TEXT,      "password"      TEXT,      "dbname"        TEXT,      "pool"  TEXT,      "pool6"  TEXT,      "pfxLength" INT,      "last"  TEXT,      "created"       TEXT,      PRIMARY KEY("id" AUTOINCREMENT) ); INSERT INTO "svctmp" (id, device, address, clientId, planId, status, "last", created)     SELECT id, device, address, clientId, planId, status, "last", created FROM "services"; INSERT INTO "devtmp" (id,name,ip,type,user,password,dbname,pool,"last",created)     SELECT id,name,ip,type,user,password,dbname,pool,"last",created FROM "devices"; DROP TABLE "services"; DROP TABLE "devices" ; ALTER TABLE "svctmp" RENAME to "services"; ALTER TABLE "devtmp" RENAME to "devices"; CREATE INDEX "xsvc_address" ON "services" ("address"); CREATE INDEX "xsvc_device" ON "services" ("device"); CREATE INDEX "xsvc_planId" ON "services" ("planId"); CREATE INDEX "xdev_name" ON "devices" ("name" COLLATE nocase);';
$Config = '{  "version": "1.8.0",  "disabled_rate": 1,  "auto_ppp_user": "false",  "pppoe_caller_attr": "callerId",  "disable_contention": "false",  "hs_enable" : "false",  "hs_attr" : "hotspot",  "auto_hs_user" : "false"}';

function prn($msg)
{
    echo $msg . "\n";
    sleep(3);
}

function find_backup(): ?string
{
    prn('looking for recent backups');
    $files = scandir('data') ?? null;
    if(!$files)exit("could not find files in plugins backup location\n");
    $backups = preg_grep('/^backup/',$files);
    if($backups) prn('found '. sizeof($backups). ' backups');
    else exit("no backup files were found - new installation?\n");
    $time = 0 ;
    $backup = '';
    foreach($backups as $b){
        if(($t = filemtime('data/'.$b)) > $time){
            $time = $t ;
            $backup = $b ;
        }
    }
    prn('using backup from '.date('Y-m-d H:i:s',$time));
    return 'data/' . $backup ;
}

function db($path=null)
{
    return new API_SQLite($path);
}

function conf_updates(): bool
{
    global $Config ;
    $conf = db()->readConfig();
    $conf_updates = json_decode($Config,true);
    $version = '1.8.5' ;
    $ret = true ;
    foreach (array_keys($conf_updates) as $key) {
        if (!isset($conf->$key)) {
            $ret &= db()->insert((object)['key' => $key,
                'value' => $conf_updates[$key]],'config');
        }
    }
    return $ret && db()->setVersion($version);
}

function repair($src='data/data.db'):bool
{
    global $update;
    try {
        if(!file_exists($src))
            throw new Exception($src. ' file does not exist in data location');
        $rep = 'data/rep';
        if(!copy($src, $rep))
            throw new Exception('failed to copy source data for repair');
        $db = db($rep);
        prn('source data opened successfully');
        prn("updating schema to 1.8.5");
        if(!$db->exec($update))
            throw new Exception('failed to apply 1.8.5 schema update');
        prn('schema update applied successfully');
        prn('saving previous data');
        $save = 'data/rep-' . time();
        if(!copy('data/data.db',$save))
            throw new Exception('failed to save previous data');
        prn('previous data has been saved as '.$save);
        prn('installing repaired data');
        if(!copy($rep,'data/data.db'))
            throw new Exception('failed to install repaired data');
        prn('repaired data installed successfully');
        prn('updating configuration to 1.8.5');
        if(!conf_updates())
            throw new Exception('configuration update to 1.8.5 failed');
        prn('configuration updated successfully');
        prn('repair completed');
        return true ;
    } catch (Exception $e){
        echo $e->getMessage() . "\n";
        return false ;
    }
}

$path = '/home/unms/data/ucrm/ucrm/data/plugins/ros-plugin';
system('clear');
if(!chdir($path))exit("Not enough permissions please try to run with sudo\n");
include_once 'lib/api_sqlite.php';
prn('attempt 1: primary data repair');
if(repair())exit();
prn('attempt 2: repair using backup');
repair(find_backup());
