<?php

namespace Tabula17\Mundae\Odf\Mutatis\Client;

use Swoole\Coroutine\Client;
use Tabula17\Mundae\Odf\Mutatis\Exception\RuntimeException;

/**
 * Cliente para el servicio de conversión de documentos ODF
 * 
 * Esta clase proporciona una interfaz para conectarse y enviar solicitudes
 * al servidor de conversión de documentos ODF.
 *
 * @package Tabula17\Mundae\Odf\Mutatis\Client
 */
class ConversionClient {
    /**
     * @var string Dirección IP o hostname del servidor
     */
    private string $host;

    /**
     * @var int Puerto del servidor
     */
    private int $port;

    /**
     * Constructor del cliente de conversión
     *
     * @param string $host Dirección IP o hostname del servidor
     * @param int $port Puerto del servidor
     */
    public function __construct(string $host = '127.0.0.1', int $port = 9501) {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Envía una solicitud de conversión al servidor
     *
     * @param string $inputPath Ruta al archivo de entrada
     * @param string $outputFormat Formato de salida deseado (ej. 'pdf', 'docx')
     * @param string|null $outputPath Ruta donde se guardará el archivo convertido
     * @param bool $async Indica si la conversión debe ser asíncrona
     * @param bool $useQueue Indica si se debe usar el sistema de cola
     * 
     * @throws RuntimeException Si hay un error de conexión con el servidor
     * @return array Respuesta del servidor con el resultado de la conversión
     */
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
