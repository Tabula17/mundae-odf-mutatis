<?php

namespace Tabula17\Mundae\Odf\Mutatis\Client;

use Swoole\Coroutine\Client;
use Tabula17\Mundae\Odf\Mutatis\Exception\RuntimeException;

/**
 * Cliente para el servicio de conversión de documentos ODF con soporte mTLS
 */
class ConversionClient {
    private string $host;
    private int $port;
    private ?string $sslCertFile;
    private ?string $sslKeyFile;
    private ?string $sslCaFile;
    private bool $sslVerifyPeer;

    /**
     * Constructor del cliente de conversión
     *
     * @param string $host Dirección IP o hostname del servidor
     * @param int $port Puerto del servidor
     * @param string|null $sslCertFile Ruta al certificado del cliente
     * @param string|null $sslKeyFile Ruta a la clave privada del cliente
     * @param string|null $sslCaFile Ruta al certificado de la CA
     * @param bool $sslVerifyPeer Verificar certificado del servidor
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 9501,
        ?string $sslCertFile = null,
        ?string $sslKeyFile = null,
        ?string $sslCaFile = null,
        bool $sslVerifyPeer = true
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->sslCertFile = $sslCertFile;
        $this->sslKeyFile = $sslKeyFile;
        $this->sslCaFile = $sslCaFile;
        $this->sslVerifyPeer = $sslVerifyPeer;
    }

    /**
     * Envía una solicitud de conversión al servidor
     * @throws RuntimeException
     */
    public function convert(
        string $inputPath,
        string $outputFormat,
        ?string $outputPath = null,
        bool $async = false,
        bool $useQueue = false
    ): array {
        $socket = new Client(SWOOLE_SOCK_TCP);

        // Configuración SSL si hay certificados
        if ($this->sslCertFile !== null && $this->sslKeyFile !== null) {
            $socket->set([
                'ssl_cert_file' => $this->sslCertFile,
                'ssl_key_file' => $this->sslKeyFile,
                'ssl_cafile' => $this->sslCaFile,
                'ssl_verify_peer' => $this->sslVerifyPeer,
                'ssl_allow_self_signed' => false,
                'timeout' => 5
            ]);
        }

        if (!$socket->connect($this->host, $this->port, 5)) {
            throw new RuntimeException("Connection failed: {$socket->errMsg} (Code: {$socket->errCode})");
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

        if ($response === false) {
            throw new RuntimeException("Failed to receive server response");
        }

        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid server response: " . json_last_error_msg());
        }

        return $decodedResponse;
    }

    /**
     * Método para verificar rápidamente la conectividad con el servidor
     */
    public function ping(): bool
    {
        try {
            $socket = new Client(SWOOLE_SOCK_TCP);

            if ($this->sslCertFile !== null) {
                $socket->set([
                    'ssl_cert_file' => $this->sslCertFile,
                    'ssl_key_file' => $this->sslKeyFile,
                    'ssl_verify_peer' => false, // Para ping no necesitamos verificar
                    'timeout' => 2
                ]);
            }

            return $socket->connect($this->host, $this->port, 2);
        } catch (\Throwable $e) {
            return false;
        }
    }
}