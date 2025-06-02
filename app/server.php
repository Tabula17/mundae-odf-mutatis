<?php
declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";

use Tabula17\Mundae\Odf\Mutatis\Server\ConversionServer;

$config = require __DIR__ . '/../config/config.php';
if (!is_array($config) || !isset($config['server'])) {
    throw new RuntimeException('Configuration file is missing or invalid.');
}
if (!isset($config['server']['host'], $config['server']['port'], $config['server']['workers'], $config['server']['task_workers'])) {
    throw new InvalidArgumentException('Server configuration is incomplete.');
}
if (!isset($config['unoserver_instances']) || !is_array($config['unoserver_instances'])) {
    throw new InvalidArgumentException('Unoserver instances configuration is missing or invalid.');
}
if (!isset($config['queue']) || !is_array($config['queue']) || !isset($config['queue']['enabled'], $config['queue']['host'], $config['queue']['port'], $config['queue']['channel'])) {
    throw new InvalidArgumentException('Queue configuration is missing or invalid.');
}
$mtlsMiddleware = null;
$mtlsConfig = [];
if (array_key_exists('ssl', $config)) {
    if (!is_array($config['ssl'])) {
        throw new InvalidArgumentException('SSL configuration must be an array.');
    }
    if ($config['ssl']['enabled'] === true) {
        $mtlsMiddleware = new Tabula17\Satelles\Utilis\Middleware\TCPmTLSAuthMiddleware(
            logger: null // Aquí puedes pasar un logger si lo necesitas
        );
        $mtlsConfig = $config['ssl'];
    }
}

// Iniciar servidor
$server = new ConversionServer(
    host: $config['server']['host'],
    port: $config['server']['port'],
    healthMonitor: new Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor(
        servers: $config['unoserver_instances'],
        checkInterval: 30,
        failureThreshold: 3,
        retryTimeout: 60
    ),
    workers: $config['server']['workers'],
    task_workers: $config['server']['task_workers'],
    concurrency: $config['concurrency'] ?? 10,
    queue: $config['queue']['enabled'] ? new Tabula17\Satelles\Utilis\Queue\RedisQueue(
        host: $config['queue']['host'],
        port: $config['queue']['port'],
        channel: $config['queue']['channel']
    ) : null,
    log_file: $config['server']['log_file'] ?? null,
    logger: null, // Aquí puedes pasar un logger si lo necesitas
    mtlsMiddleware: $mtlsMiddleware,
    sslSettings: $mtlsConfig
);
$server->start();