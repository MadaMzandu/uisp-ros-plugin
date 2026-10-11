<?php
require_once __DIR__ . '/api_job_queue.php';

class ApiJobs
{
    private object $data;
    private ApiJobQueue $queue;

    public function __construct($data = null, ?ApiJobQueue $queue = null)
    {
        $this->data = is_object($data) ? $data : (object) ($data ?? []);
        $this->queue = $queue ?? myJobQueue();
    }

    public function list(): array
    {
        return array_values($this->queue->list());
    }

    public function clear(): array
    {
        return $this->queue->delete(array_keys($this->queue->list()));
    }

    public function delete(): array
    {
        $keys = $this->data->data ?? [$this->data->id ?? null];
        return $this->queue->delete(is_array($keys) ? $keys : [$keys]);
    }

    public function run(?array $keys = null): array
    {
        set_time_limit(7200);
        return $this->queue->run($keys);
    }
}
