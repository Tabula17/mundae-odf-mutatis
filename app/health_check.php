<?php
// health_check.php

/**
 * Script de comprobación de salud para el servicio de conversión TCP
 *
 * Uso: php health_check.php [--port=9501] [--host=127.0.0.1] [--timeout=2]
 */

$options = getopt('', ['host:', 'port:', 'timeout:', 'verbose']);
$host = $options['host'] ?? '127.0.0.1';
$port = (int)($options['port'] ?? 9501);
$timeout = (int)($options['timeout'] ?? 2);
$verbose = isset($options['verbose']);

function checkTcpService(string $host, int $port, int $timeout): bool
{
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

    if ($socket) {
        fclose($socket);
        return true;
    }

    return false;
}

function sendTestRequest(string $host, int $port): bool
{
    $socket = fsockopen($host, $port, $errno, $errstr, 2);

    if (!$socket) {
        return false;
    }

    $testData = json_encode(['ping' => true]);
    fwrite($socket, $testData);

    $response = fread($socket, 1024);
    fclose($socket);

    return !empty($response);
}

// Comprobación básica de conexión
$serviceUp = checkTcpService($host, $port, $timeout);

if ($verbose) {
    echo "=== Health Check TCP Service ===\n";
    echo "Host: $host\n";
    echo "Port: $port\n";
    echo "Timeout: {$timeout}s\n";
    echo "Connection: " . ($serviceUp ? 'OK' : 'FAILED') . "\n";
}

if (!$serviceUp) {
    if ($verbose) echo "Service is DOWN\n";
    exit(1); // Código de error para sistemas de monitoreo
}

// Comprobación adicional con request de prueba (opcional)
try {
    $testRequestPassed = sendTestRequest($host, $port);

    if ($verbose) {
        echo "Test Request: " . ($testRequestPassed ? 'OK' : 'FAILED') . "\n";
    }

    if (!$testRequestPassed) {
        exit(2); // Servicio responde pero no funciona correctamente
    }

} catch (Exception $e) {
    if ($verbose) echo "Test Request Error: " . $e->getMessage() . "\n";
    exit(3); // Error durante la prueba
}

if ($verbose) echo "Service is UP and responding correctly\n";
exit(0); // Todo OK