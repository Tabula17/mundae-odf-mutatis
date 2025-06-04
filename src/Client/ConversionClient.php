<?php

namespace Tabula17\Mundae\Odf\Mutatis\Client;

use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\System;
use Tabula17\Mundae\Odf\Mutatis\Exception\InvalidArgumentException;
use Tabula17\Mundae\Odf\Mutatis\Exception\RuntimeException;
use Tabula17\Satelles\Utilis\Console\VerboseTrait;

/**
 * Cliente para el servicio de conversión de documentos ODF con soporte mTLS
 */
class ConversionClient
{
    use VerboseTrait;

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
        string               $host = '127.0.0.1',
        int                  $port = 9501,
        ?string              $sslCertFile = null,
        ?string              $sslKeyFile = null,
        ?string              $sslCaFile = null,
        bool                 $sslVerifyPeer = true,
        private int          $chunkSize = 8192, // Tamaño de chunk para lectura de archivos
        private float        $timeout = 5, // Timeout para conexiones
        private readonly int $verbose = self::ERROR // Nivel de verbosidad para debug
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
        string  $mode = 'stream',
        int     $chunkSize = 8192
    ): array
    {
        // Validación básica
        if ($filePath === null && $fileContent === null) {
            $this->error("Debe proveer filePath o fileContent");
            throw new InvalidArgumentException("Debe proveer filePath o fileContent");
        }
        $socket = new Client(SWOOLE_SOCK_TCP);
        $socket->set([
            'timeout' => $this->timeout,
            'connect_timeout' => 2.0,
            'package_max_length' => 10 * 1024 * 1024 // 10MB
        ]);
        // Configuración SSL si hay certificados
        if ($this->sslCertFile !== null && $this->sslKeyFile !== null) {
            $socket->set([
                'ssl_cert_file' => $this->sslCertFile,
                'ssl_key_file' => $this->sslKeyFile,
                'ssl_cafile' => $this->sslCaFile,
                'ssl_verify_peer' => $this->sslVerifyPeer,
                'ssl_allow_self_signed' => false
            ]);
        }
        if (!$socket->connect($this->host, $this->port, 5)) {
            $this->error("Error al conectar al host {$this->host}: {$socket->errMsg} (Código: {$socket->errCode})"); // Debug
            throw new RuntimeException("Connection failed: {$socket->errMsg} (Code: {$socket->errCode})");
        }
        try {
            // Enviar metadata inicial
            $metadata = [
                'action' => 'start_upload',
                'output_format' => $outputFormat,
                'output_path' => $outputPath,
                'async' => $async,
                'queue' => $useQueue,
                'mode' => $mode,
                'chunk_size' => $chunkSize
            ];
            if ($filePath !== null) {
                $metadata['file_name'] = basename($filePath);
                $metadata['file_size'] = filesize($filePath);
            }
            if (!$socket->send(json_encode($metadata))) {
                $this->error("[Error] Enviando metadata: " . $socket->errMsg); // Debug
                throw new RuntimeException("Error al enviar metadata: {$socket->errMsg}");
            }
            // Procesar el contenido del archivo
            if ($fileContent !== null) {
                // Si nos dan el contenido directamente
                $this->sendContentInChunks($socket, $fileContent, $chunkSize);
            } else {
                // Si nos dan una ruta de archivo
                $this->sendFileInChunks($socket, $filePath, $chunkSize);
            }

            // Indicar fin de transmisión
            $socket->send(json_encode(['action' => 'end_upload']));
            $response = $this->waitForResponse($socket, "\n", $this->timeout);
            //return json_decode($response, true);

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("[Error] Respuesta JSON inválida: " . json_last_error_msg()); // Debug
                throw new RuntimeException("Respuesta inválida: " . json_last_error_msg());
            }

            return $decoded;
        } finally {
            $socket->close();
        }
    }

    private function sendFileInChunks(Client $socket, string $filePath, int $chunkSize): void
    {
        $file = fopen($filePath, 'rb');
        if ($file === false) {
            throw new RuntimeException("No se pudo abrir el archivo: $filePath");
        }

        // 1. Esperar READY del servidor
        $ready = $this->waitForResponse($socket, "READY\n");
        if ($ready !== "READY\n") {
            fclose($file);
            throw new RuntimeException("Protocol error: Expected READY, got " . ($ready ?: "empty response"));
        }

        // 2. Enviar chunks y esperar ACK
        while (!feof($file)) {
            $chunk = fread($file, $chunkSize);
            $this->sendChunk($socket, $chunk);
        }

        fclose($file);
    }

    private function sendContentInChunks(Client $socket, string $content, int $chunkSize): void
    {
        // 1. Esperar READY del servidor
        $ready = $this->waitForResponse($socket, "READY\n");
        if ($ready !== "READY\n") {
            throw new RuntimeException("Protocol error: Expected READY, got " . ($ready ?: "empty response"));
        }

        // 2. Enviar chunks y esperar ACK
        $length = strlen($content);
        for ($offset = 0; $offset < $length; $offset += $chunkSize) {
            $chunk = substr($content, $offset, $chunkSize);
            $this->sendChunk($socket, $chunk);
        }
    }

    private function sendChunk(Client $socket, string $chunk): void
    {
        $payload = json_encode([
                'action' => 'chunk',
                'data' => base64_encode($chunk),
                'size' => strlen($chunk)
            ]) . "\n";  // Asegurar terminación con \n

        if (!$socket->send($payload)) {
            throw new RuntimeException("Error al enviar chunk: {$socket->errMsg}");
        }

        // Esperar ACK con timeout
        $ack = $this->waitForResponse($socket, "ACK\n");
        if ($ack !== "ACK\n") {
            throw new RuntimeException("Error en confirmación del chunk");
        }
    }

    private function waitForResponse(Client $socket, string $expected, float $timeout = 5.0): string
    {
        $startTime = microtime(true);
        $response = '';

        while (true) {
            // Verificar timeout
            if ((microtime(true) - $startTime) > $timeout) {
                throw new RuntimeException("Timeout esperando respuesta del servidor");
            }

            $data = $socket->recv(1.0); // Timeout corto para no bloquear indefinidamente

            if ($data === false) {
                throw new RuntimeException("Error de conexión: {$socket->errMsg}");
            }

            if ($data !== '') {
                $response .= $data;

                // Verificar si tenemos la respuesta completa
                if (strpos($response, "\n") !== false) {
                    // Extraer solo la línea completa
                    $lines = explode("\n", $response, 2);
                    $completeLine = $lines[0] . "\n";

                    // Guardar el resto para la próxima lectura
                    $response = $lines[1] ?? '';

                    return $completeLine;
                }
            }

            // Pequeña pausa para evitar uso intensivo de CPU
            usleep(10000); // 10ms
        }
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
                $this->error("[Error] Al convertir (async): " . $e->getMessage()); // Debug
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
            $this->error("Error al conectar al host {$this->host}: " . $e->getMessage()); // Debug
            return false;
        }
    }

    private function isVerbose(int $level): bool
    {
        $this->verboseIcon = '🌏';
        return $level >= $this->verbose;
    }
}