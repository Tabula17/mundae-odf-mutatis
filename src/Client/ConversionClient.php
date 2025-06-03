<?php

namespace Tabula17\Mundae\Odf\Mutatis\Client;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\System;
use Tabula17\Mundae\Odf\Mutatis\Exception\RuntimeException;

/**
 * Cliente para el servicio de conversión de documentos ODF con soporte mTLS
 */
class ConversionClient
{
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
        string  $host = '127.0.0.1',
        int     $port = 9501,
        ?string $sslCertFile = null,
        ?string $sslKeyFile = null,
        ?string $sslCaFile = null,
        bool    $sslVerifyPeer = true
    )
    {
        $this->host = $host;
        $this->port = $port;
        $this->sslCertFile = $sslCertFile;
        $this->sslKeyFile = $sslKeyFile;
        $this->sslCaFile = $sslCaFile;
        $this->sslVerifyPeer = $sslVerifyPeer;
    }


    /**
     * Convierte un documento (usando path o contenido directo)
     *
     * @param string|null $filePath Ruta del archivo (opcional si se provee fileContent)
     * @param string $outputFormat Formato de salida (pdf, docx, etc)
     * @param string|null $fileContent Contenido del archivo (opcional)
     * @param string|null $outputPath Ruta de salida (opcional)
     * @param bool $async Procesamiento asíncrono
     * @param bool $useQueue Usar cola de mensajes
     * @param string $mode Modo de operación (stream|file)
     *
     * @return array Resultado de la conversión
     * @throws RuntimeException
     */
    public function convert(
        ?string $filePath = null,
        string  $outputFormat = 'pdf',
        ?string $fileContent = null,
        ?string $outputPath = null,
        bool    $async = false,
        bool    $useQueue = false,
        string  $mode = 'stream'
    ): array
    {
        $socket = new Client(SWOOLE_SOCK_TCP);


        // Validación básica
        if ($filePath === null && $fileContent === null) {
            throw new \InvalidArgumentException("Debe proveer filePath o fileContent");
        }
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
            'file_path' => $filePath,
            'file_content' => $fileContent,
            'output_format' => $outputFormat,
            'output_path' => $outputPath,
            'async' => $async,
            'queue' => $useQueue,
            'mode' => $mode
        ];
        // Leer el archivo si no se provee contenido (en corrutina para no bloquear)
        if ($fileContent === null && $filePath !== null && $mode === 'stream') {
            $request['file_content'] = System::readFile($filePath);
            if ($request['file_content'] === false) {
                throw new \RuntimeException("No se pudo leer el archivo: $filePath");
            }
            // Opcional: enviar metadata si tenemos filePath
            $request['file_name'] = basename($filePath);
            $request['file_size'] = strlen($request['file_content']);
            $request['file_path'] = null; // No necesitamos el path si usamos contenido
        }

        $socket->send(json_encode($request, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_IGNORE ));

        while (true) {
            $response = $socket->recv();
            if ($response !== false) {
                // Si recibimos una respuesta, salimos del bucle
                $socket->close();
                break;
            }

            if ($socket->errCode !== SOCKET_ETIMEDOUT) {
                $socket->close();
                throw new RuntimeException("Failed to receive server response {$socket->errCode}");
            }


        }

        $decodedResponse = json_decode($response, true, 512, JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE | JSON_INVALID_UTF8_IGNORE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Invalid server response: " . json_last_error_msg());
        }

        return $decodedResponse;
    }


    /**
     * Versión asíncrona con callback
     */
    public function convertAsync(
        $fileInput, // Puede ser path (string) o contenido (string)
        string $outputFormat,
        callable $callback,
        ?string $outputPath = null,
        bool $useQueue = false
    ): void
    {
        Coroutine::create(function () use ($fileInput, $outputFormat, $callback, $outputPath, $useQueue) {
            try {
                if (is_string($fileInput) && is_file($fileInput)) {
                    $result = $this->convert($fileInput, $outputFormat, null, $outputPath, true, $useQueue);
                } else {
                    $result = $this->convert(null, $outputFormat, $fileInput, $outputPath, true, $useQueue);
                }

                $callback($result);
            } catch (\Throwable $e) {
                $callback([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }
        });
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