<?php

namespace Tabula17\Mundae\Odf\Mutatis\Server;

use Swoole\Server;
use Tabula17\Mundae\Odf\Mutatis\Exception\InvalidArgumentException;
use Tabula17\Mundae\Odf\Mutatis\Exception\RuntimeException;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;
use Tabula17\Satelles\Utilis\Queue\QueueInterface;
use Tabula17\Satelles\Utilis\Middleware\TCPmTLSAuthMiddleware;
use Psr\Log\LoggerInterface;

/**
 * Servidor de conversión de documentos ODF
 *
 * Implementa un servidor TCP basado en Swoole que maneja solicitudes de conversión
 * de documentos, soportando procesamiento síncrono y asíncrono, balanceo de carga
 * y autenticación mTLS.
 *
 * @package Tabula17\Mundae\Odf\Mutatis\Server
 */
class ConversionServer
{
    /**
     * @var Server Instancia del servidor Swoole
     */
    private Server $server;

    /**
     * @var UnoserverLoadBalancer Gestor de balanceo de carga para instancias unoserver
     */
    private UnoserverLoadBalancer $converter;

    /**
     * @var bool Estado actual del servidor
     */
    private bool $isRunning = false;

    /**
     * @var bool Indica si el sistema de cola está habilitado
     */
    private bool $queueEnabled;

    /**
     * Constructor del servidor de conversión
     *
     * @param string $host Dirección IP para escuchar
     * @param int $port Puerto para escuchar
     * @param ServerHealthMonitor $healthMonitor Monitor de salud de instancias unoserver
     * @param int $workers Número de workers
     * @param int $task_workers Número de task workers
     * @param int $concurrency Límite de conversiones simultáneas
     * @param QueueInterface|null $queue Sistema de cola (opcional)
     * @param string|null $log_file Ruta al archivo de log
     * @param LoggerInterface|null $logger Logger PSR-3 (opcional)
     * @param TCPmTLSAuthMiddleware|null $mtlsMiddleware Middleware para mTLS (opcional)
     * @param array|null $sslSettings Configuración SSL (requerida si se usa mTLS)
     */
    public function __construct(
        private readonly string                 $host,
        private readonly int                    $port,
        private readonly ServerHealthMonitor    $healthMonitor,
        private readonly int                    $workers = 4,
        private readonly int                    $task_workers = 8,
        private readonly int                    $concurrency = 10,
        private readonly ?QueueInterface        $queue = null,
        private readonly ?string                $log_file = null,
        private readonly ?LoggerInterface       $logger = null,
        private readonly ?TCPmTLSAuthMiddleware $mtlsMiddleware = null,
        private readonly ?array                 $sslSettings = null
    )
    {
        $this->queueEnabled = $queue !== null;
    }

    /**
     * Inicializa el balanceador de carga de unoserver
     *
     * @return void
     */
    private function initializeConverter(): void
    {
        $this->converter = new UnoserverLoadBalancer(
            $this->healthMonitor,
            $this->concurrency ?? 10
        );
    }

    /**
     * Inicializa todos los componentes necesarios para el servidor
     *
     * @return void
     */
    private function initializeComponents(): void
    {
        $this->initializeConverter();
    }

    /**
     * Inicia el servidor de conversión
     *
     * @return void
     * @throws RuntimeException Si el servidor ya está en ejecución
     */
    public function start(): void
    {
        if ($this->isRunning) {
            throw new RuntimeException("Server is already running");
        }

        $this->initializeComponents();
        $this->createServer();
        $this->registerCallbacks();

        $this->isRunning = true;
        $this->server->start();
    }

    /**
     * Valida y completa la configuración SSL
     *
     * @param array $settings Configuración SSL a validar
     * @return array Configuración SSL validada y completada
     * @throws InvalidArgumentException Si faltan configuraciones requeridas
     */
    private function validateSSLSettings(array $settings): array
    {
        $requiredKeys = ['ssl_cert_file', 'ssl_key_file', 'ssl_client_cert_file'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $settings) || empty($settings[$key])) {
                throw new InvalidArgumentException("Missing required SSL setting: $key");
            }
        }
        if (!is_readable($settings['ssl_cert_file']) || !is_readable($settings['ssl_key_file'])) {
            throw new InvalidArgumentException("SSL certificate or key file is not readable.");
        }
        if ($this->mtlsMiddleware !== null) {
            if (!$settings['ssl_client_cert_file']) {
                $settings['ssl_client_cert_file'] = true; // Default to true for mTLS
            }
            if (!array_key_exists('ssl_verify_peer', $settings) || !isset($settings['ssl_verify_peer'])) {
                $settings['ssl_verify_peer'] = true; // Default to true for security
            }

            if (!array_key_exists('ssl_allow_self_signed', $settings) || !isset($settings['ssl_allow_self_signed'])) {
                $settings['ssl_allow_self_signed'] = false; // Default to false for security
            }
        }
        return $settings;
    }

    /**
     * Crea y configura el servidor Swoole
     *
     * @return void
     */
    private function createServer(): void
    {
        $this->server = new Server(
            $this->host,
            $this->port,
            SWOOLE_PROCESS,
            SWOOLE_SOCK_TCP
        );

        $serverSettings = [
            'worker_num' => $this->workers ?? 4,
            'task_worker_num' => $this->task_workers ?? 8,
            'enable_coroutine' => true,
            'log_file' => $this->log_file ?? '/tmp/conversion_server.log',
            'hook_flags' => SWOOLE_HOOK_ALL
        ];

        // Configuración TLS si hay middleware mTLS
        if ($this->mtlsMiddleware !== null) {
            $serverSettings = array_merge($serverSettings, $this->validateSSLSettings($this->sslSettings));
        }

        $this->server->set($serverSettings);
    }

    /**
     * Registra los callbacks del servidor
     *
     * @return void
     */
    private function registerCallbacks(): void
    {
        $this->server->on('start', function (Server $server) {
            $this->logger?->info("Servidor iniciado en {$this->host}:{$this->port}");
            $this->healthMonitor->startMonitoring();
        });

        $this->server->on('workerStart', function (Server $server, int $workerId) {
            if ($workerId < $server->setting['worker_num']) {
                $this->converter->start();
                $this->logger?->debug("Worker #$workerId iniciado");
            }
        });

        $this->server->on('connect', function (Server $server, int $fd) {
            $this->logger?->debug("Cliente conectado", ['fd' => $fd]);
        });

        $this->server->on('receive', function (Server $server, int $fd, int $reactorId, string $data) {
            $this->handleIncomingRequest($server, $fd, $data);
        });

        $this->server->on('task', function (Server $server, int $taskId, int $workerId, array $data) {
            return $this->processTask($data);
        });

        $this->server->on('finish', function (Server $server, int $taskId, array $data) {
            $this->sendResponse($data['fd'], $data['result']);
        });

        $this->server->on('close', function (Server $server, int $fd) {
            $this->logger?->debug("Cliente desconectado", ['fd' => $fd]);
        });
        $this->server->on('shutdown', function (Server $server) {
            $this->logger?->info("Servidor detenido");
            $this->healthMonitor->stopMonitoring();
            $this->converter->stop();
        });
        $this->server->on('error', function (Server $server, int $code, string $message) {
            $this->logger?->error("Error del servidor: {$message} (Código: {$code})");
        });
        $this->server->on('pipeMessage', function (Server $server, int $fromWorkerId, string $message) {
            $this->logger?->debug("Mensaje de pipe recibido", [
                'fromWorkerId' => $fromWorkerId,
                'message' => $message
            ]);
        });
        $this->server->on('workerError', function (Server $server, int $workerId, int $code, string $message) {
            $this->logger?->error("Error en el worker #$workerId: {$message} (Código: {$code})");
        });
    }

    /**
     * Maneja las solicitudes entrantes
     *
     * @param Server $server Instancia del servidor
     * @param int $fd Descriptor de archivo del cliente
     * @param string $data Datos recibidos
     * @return void
     */
    private function handleIncomingRequest(Server $server, int $fd, string $data): void
    {
        if ($this->mtlsMiddleware !== null) {
            $this->mtlsMiddleware->handle($server, $fd, $data, function ($server, $context) {
                $this->processAuthenticatedRequest($context['fd'], $context['data']);
            });
        } else {
            $this->processRequest($fd, $data);
        }
    }

    /**
     * Procesa una solicitud autenticada
     *
     * @param int $fd Descriptor de archivo del cliente
     * @param string $data Datos de la solicitud
     * @return void
     */
    private function processAuthenticatedRequest(int $fd, string $data): void
    {
        try {
            $this->logger?->debug("Procesando solicitud autenticada", ['fd' => $fd]);
            $this->processRequest($fd, $data);
        } catch (\Throwable $e) {
            $this->logger?->error("Error procesando solicitud autenticada", [
                'fd' => $fd,
                'error' => $e->getMessage()
            ]);
            $this->sendError($fd, "Error de procesamiento: " . $e->getMessage());
        }
    }

    /**
     * Procesa una solicitud de conversión
     *
     * @param int $fd Descriptor de archivo del cliente
     * @param string $data Datos JSON de la solicitud
     * @return void
     */
    private function processRequest(int $fd, string $data): void
    {
        try {
            $request = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            echo "Received request: " . json_encode($request) . "\n";
            if ($this->shouldProcessAsync($request)) {
                $this->processAsync($fd, $request);
            } else {
                $this->processSync($fd, $request);
            }
        } catch (\JsonException $e) {
            $this->sendError($fd, "Invalid JSON: " . $e->getMessage());
        } catch (\Throwable $e) {
            $this->sendError($fd, "Server error: " . $e->getMessage());
        }
    }

    /**
     * Determina si la solicitud debe procesarse de forma asíncrona
     *
     * @param array $request Datos de la solicitud
     * @return bool
     */
    private function shouldProcessAsync(array $request): bool
    {
        return ($request['async'] ?? false) ||
            ($this->queueEnabled && ($request['queue'] ?? false));
    }

    /**
     * Procesa una solicitud de forma síncrona
     *
     * @param int $fd Descriptor de archivo del cliente
     * @param array $request Datos de la solicitud
     * @return void
     */
    private function processSync(int $fd, array $request): void
    {
        try {
            if (empty($request['file_path']) && empty($request['file_content'])) {
                throw new InvalidArgumentException("Debe proporcionar 'file_path' o 'file_content'");
            }
            $result = $this->converter->convertSync(
                filePath: $request['file_path'] ?? null,
                fileContent: $request['file_content'] ?? null,
                outputFormat: $request['output_format'] ?? 'pdf',
                outPath: $request['output_path'] ?? null,
                mode: $request['mode'] ?? 'stream'
            );

            $this->sendResponse($fd, [
                'status' => 'success',
                'result' => $result
            ]);
        } catch (\Throwable $e) {
            $this->sendError($fd, $e->getMessage());
        }
    }

    /**
     * Procesa una solicitud de forma asíncrona
     *
     * @param int $fd Descriptor de archivo del cliente
     * @param array $request Datos de la solicitud
     * @return void
     */
    private function processAsync(int $fd, array $request): void
    {
        if ($this->queue && ($request['queue'] ?? false)) {
            $taskId = $this->queue->push($request);
            $this->sendResponse($fd, [
                'status' => 'queued',
                'task_id' => $taskId
            ]);
        } else {
            $this->server->task([
                'fd' => $fd,
                'request' => $request
            ]);
        }
    }

    /**
     * Procesa una tarea asíncrona
     *
     * @param array $taskData Datos de la tarea
     * @return array Resultado del procesamiento
     */
    private function processTask(array $taskData): array
    {
        try {
            $result = $this->converter->convertSync(
                filePath: $taskData['request']['file_path'],
                fileContent: $taskData['request']['output_format'],
                outputFormat: $taskData['request']['file_content'] ?? null,
                outPath: $taskData['request']['output_path'] ?? null,
                mode: $taskData['request']['mode'] ?? 'stream'
            );

            return [
                'fd' => $taskData['fd'],
                'result' => [
                    'status' => 'success',
                    'result' => $result
                ]
            ];
        } catch (\Throwable $e) {
            return [
                'fd' => $taskData['fd'],
                'result' => [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Envía una respuesta al cliente
     *
     * @param int $fd Descriptor de archivo del cliente
     * @param array $response Datos de la respuesta
     * @return void
     */
    private function sendResponse(int $fd, array $response): void
    {
        $this->server->send($fd, json_encode($response));
    }

    /**
     * Envía un mensaje de error al cliente
     *
     * @param int $fd Descriptor de archivo del cliente
     * @param string $message Mensaje de error
     * @return void
     */
    private function sendError(int $fd, string $message): void
    {
        $this->sendResponse($fd, [
            'status' => 'error',
            'message' => $message
        ]);
    }

    /**
     * Obtiene el resultado de una tarea
     *
     * @param string $taskId ID de la tarea
     * @return array|null Resultado de la tarea o null si no existe
     */
    public function getResult(string $taskId): ?array
    {
        if ($this->queueEnabled) {
            return $this->queue->getResult($taskId);
        }
        return null;
    }
}
