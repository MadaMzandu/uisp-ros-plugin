<?php
require_once __DIR__ . '/lib/api_jobs.php';
$data = [];
$jobs = new Api_Jobs($data);
$jobs->run();