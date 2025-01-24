<?php
require_once __DIR__ . '/lib/api_jobs.php';
$data = [];
$jobs = new ApiJobs($data);
$jobs->run();

include_once "lib/api_ip_mgmt.php";