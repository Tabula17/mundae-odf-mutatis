<?php
// Configuración
return [
    'server' => [
        'host' => '0.0.0.0',
        'port' => 9501,
        'workers' => 4,
        'task_workers' => 8,
        'package_max_length' => 1024 * 1024 * 100, // 10 MB
        'log_file' => '/var/log/conversion_server.log'
    ],
    'unoserver_instances' => [
        ['host' => '127.0.0.1', 'port' => 2003],
        ['host' => '127.0.0.1', 'port' => 2004],
        ['host' => '127.0.0.1', 'port' => 2005]
    ],
    'concurrency' => 20,
    'queue' => [
        'enabled' => true,
        'driver' => 'RedisQueue', // o RabbitMQQueue
        'host' => '127.0.0.1',
        'port' => 6379,
        'channel' => 'document_conversions'
    ],
    'ssl' => [
        'enabled' => false,
        'ssl_cert_file' => '/path/to/cert.pem',
        'ssl_key_file' => '/path/to/key.pem',
        'ssl_client_cert_file' => true,
        'ssl_verify_peer' => true,
        'ssl_allow_self_signed' => false
    ],
];