<?php

namespace Tabula17\Mundae\Odf\Mutatis\Client;

use Swoole\Coroutine\Client;
use Tabula17\Mundae\Odf\Mutatis\Exception\RuntimeException;

class ConversionClient {
    private string $host;
    private int $port;

    public function __construct(string $host = '127.0.0.1', int $port = 9501) {
        $this->host = $host;
        $this->port = $port;
    }

    public function convert(
        string $inputPath,
        string $outputFormat,
        ?string $outputPath = null,
        bool $async = false,
        bool $useQueue = false
    ): array {
        $socket = new Client(SWOOLE_SOCK_TCP);

        if (!$socket->connect($this->host, $this->port, 5)) {
            throw new RuntimeException("Connection failed: {$socket->errMsg}");
        }

        $request = [
            'file_path' => $inputPath,
            'output_format' => $outputFormat,
            'output_path' => $outputPath,
            'async' => $async,
            'queue' => $useQueue
        ];

        $socket->send(json_encode($request));
        $response = $socket->recv();
        $socket->close();

        return json_decode($response, true);
    }
}
/*
 * Ejemplo de uso del cliente de conversión:
 *
 * Este cliente se conecta a un servidor de conversión que escucha en el puerto 9501
 * y envía una solicitud para convertir un archivo ODT a PDF.
 *
 * Asegúrate de que el servidor esté corriendo antes de ejecutar este código.
 *
// Uso:
$client = new ConversionClient();
$result = $client->convert(
    '/docs/informe.odt',
    'pdf',
    '/docs/informe.pdf',
    true,  // async
    false  // no usar cola
);

print_r($result);*/