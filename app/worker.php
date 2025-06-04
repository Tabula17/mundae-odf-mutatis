<?php
declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";

// Ejecutar worker
use Tabula17\Mundae\Odf\Mutatis\Server\NoConversionServer;
use Tabula17\Mundae\Odf\Mutatis\Worker\ConversionWorker;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Utilis\Queue\RedisQueue;


$config = require __DIR__ . '/../config/config.php';

$healthMonitor = new ServerHealthMonitor(
    servers: $config['unoserver_instances'],
    checkInterval: 30,
    failureThreshold: 3,
    retryTimeout: 60
);
$queue = new RedisQueue(
    host: $config['queue']['host'],
    port: $config['queue']['port'],
    channel: $config['queue']['channel']
);
$worker = new ConversionWorker(
    queue: $queue,
    healthMonitor: $healthMonitor,
    concurrency: $config['concurrency'] ?? 4,
    timeout: 10
);
$worker->start();