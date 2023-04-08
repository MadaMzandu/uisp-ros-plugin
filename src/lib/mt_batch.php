<?php
include_once 'mt.php';
include_once 'mt_data.php';
include_once 'api_ip.php';
include_once 'api_sqlite.php';

class MtBatch extends MT
{
    public function delete_ids(array $ids)
    {
        $deviceServices = $this->select_ids($ids);
        $plans = $this->select_plans();
        $deviceData = [];
        $mt = new MtData();
        foreach (array_keys($deviceServices) as $did){
            foreach($deviceServices[$did] as $service){
                $plan = $plans[$service['planId']] ;
                $account = $mt->account($service,$plan);
                if($account){
                    $account['action'] = 'remove';
                    $deviceData[$did]['accounts'][] = $account ;
                }
                $queue = $mt->queue($service,$plan);
                if($queue){
                    $queue['action'] = 'remove';
                    $deviceData[$did]['queues'][] = $queue ;
                }
                $profile = $mt->profile($service,$plan);
                if($profile){
                    $profile['action'] = 'remove';
                    $deviceData[$did]['profiles'][$profile['name']] = $profile ;
                }
                $parent = $mt->parent($service,$plan);
                if($parent){
                    $parent['action'] = 'remove';
                    $deviceData[$did]['parents'][$parent['name']] = $parent ;
                }
                $disconnect = $mt->disconnect($service);
                if($disconnect){$deviceData[$did]['disconn'][] = $disconnect; }
            }
        }
        MyLog()->Append('services ready to delete');
        $this->run_batch($deviceData,true);
        $this->delete_batch($deviceServices);
    }

    public function set_ids(array $ids)
    {
        $deviceServices = $this->select_ids($ids);
        $devices = $this->select_devices();
        $plans = $this->select_plans();
        $deviceData = [];
        $mt = new MtData();
        foreach (array_keys($deviceServices) as $did){
            foreach ($deviceServices[$did] as $service){
                $plan = $plans[$service['planId']] ;
                $device = $devices[$service['device']] ?? null;
                $service['address'] = $mt->ip($service,$device);
                $account = $mt->account($service,$plan);
                if($account){ $deviceData[$did]['accounts'][] = $account ; }
                $queue = $mt->queue($service,$plan);
                if($queue){ $deviceData[$did]['queues'][] = $queue ; }
                $profile = $mt->profile($service,$plan);
                if($profile){ $deviceData[$did]['profiles'][$profile['name']] = $profile ; }
                $parent = $mt->parent($service,$plan);
                if($parent){ $deviceData[$did]['parents'][$parent['name']] = $parent ; }
                $disconnect = $mt->disconnect($service);
                if($disconnect){$deviceData[$did]['disconn'][] = $disconnect; }
            }
        }
        MyLog()->Append('services ready to add or set');
        $this->run_batch($deviceData);
        $this->save_batch($deviceServices);
    }

    private function run_batch($deviceData,$delete = false)
    {
        $timer = new ApiTimer("mt batch write");
        $devices = $this->select_devices();
        $sent = 0 ;
        $writes = 0 ;
        $this->batch_failed = [];
        $this->batch_success = [];
        foreach (array_keys($deviceData) as $did)
        {
            if($did == 'nokey') { continue; }
            $this->batch_device = (object) $devices[$did];
            MyLog()->Append('executing batch for device: '.$this->batch_device->name);
            $keys = ['parents','profiles','queues','accounts','disconn'];
            if($delete) $keys = array_reverse($keys);
            foreach ($keys as $key){
                $item = $deviceData[$did][$key] ?? [];
                $this->batch = array_values($item);
                $sent += sizeof($this->batch);
                $writes += $this->write_batch();
            }
        }
        $timer->stop();
        MyLog()->Append(sprintf('batch sent: %s written: %s',$sent,$writes));
    }

    private function save_batch($deviceServices)
    {
        $successes = $this->find_success();
        $fields = [
            'id',
            'device',
            'clientId',
            'planId',
            'status',
        ];
        $save = [];
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $values = [];
                $key = $service['mac'] ?? $service['username'] ?? null;
                $success = $successes[strtolower($key)] ?? null ;
                if($success){
                    foreach ($fields as $key){ $values[] = $service[$key] ?? null ;}
                    $values['last'] = $this->now();
                    $save[] = $values;
                }
            }
        }
        $sql = sprintf("insert or replace into services (%s,last) values ",implode(',',$fields));
        $sql .= $this->to_sql($save);
        MyLog()->Append('batch: saving data '.$sql);
        $this->db()->exec($sql);
    }

    private function delete_batch($deviceServices)
    {
        $successes = $this->find_success();
        $ids = [];
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $key = $service['mac'] ?? $service['username'] ?? null ;
                $success = $successes[strtolower($key)] ?? null ;
                if($success){
                    $ids[] = $service['id'];
                }
            }
        }
        foreach(['services','network'] as $table) {
            $sql = sprintf("delete from %s where id in (%s)",$table,
                implode(',',$ids));
            MyLog()->Append(sprintf("batch delete from %s sql: %s",$table,$sql));
            $this->db()->exec($sql);
        }



    }

    private function find_success(): array
    {
        $successes = [];
        foreach ($this->batch_success as $item){
            $key = $item['mac-address'] ?? $item['name'] ?? 'nokey';
            $key =strtolower($key);
            $successes[$key] = 1 ;
        }
        return $successes ;
    }

    private function to_sql($array): string
    {
        $query = [];
        foreach ($array as $row){
            $values = [];
            foreach($row as $value){
                if(is_null($value)) $values[] = 'null';
                else if(is_numeric($value)) $values[] = $value;
                else $values[] = sprintf("'%s'",$value);
            }
            $query[] = sprintf("(%s)",implode(',',$values));
        }
        return implode(',',$query);
    }

    private function select_plans(): array
    {
        $api = new AdminPlans();
        $api->get();
        $plans = $api->result();
        $map = [];
        foreach ($plans as $plan){ $map[$plan['id']] = $plan; }
        return $map ;
    }

    private function select_ids(array $ids): array
    {
        $fields = 'services.*,clients.company,clients.firstName,clients.lastName';
        $sql = sprintf("SELECT %s FROM services LEFT JOIN clients ON services.clientId=clients.id ".
            "LEFT JOIN network ON services.id=network.id ".
            "WHERE services.id IN (%s) ",$fields,implode(',',$ids));
        $data = $this->dbCache()->selectCustom($sql);
        $deviceMap = [];
        foreach ($data as $item){ $id = $item['device'] ?? 'nodev'; $deviceMap[$id][] = $item ; }
        return $deviceMap ;
    }

    private function select_devices(): array
    {
        $devs = $this->db()->selectAllFromTable('devices') ?? [];
        $map = [];
        foreach ($devs as $dev){ $map[$dev['id']] = $dev; }
        return $map ;
    }

    private function now(): string {$date = new DateTime(); return $date->format('Y-m-d H:i:s'); }


}