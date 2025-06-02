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

class ConversionServer
{
    private Server $server;
    private UnoserverLoadBalancer $converter;
    private bool $isRunning = false;
    private bool $queueEnabled;

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

    private function initializeConverter(): void
    {
        $this->converter = new UnoserverLoadBalancer(
            $this->healthMonitor,
            $this->concurrency ?? 10
        );
    }

    private function initializeComponents(): void
    {
        $this->initializeConverter();
    }

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
    }

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

    private function processRequest(int $fd, string $data): void
    {
        try {
            $request = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

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

    private function shouldProcessAsync(array $request): bool
    {
        return ($request['async'] ?? false) ||
            ($this->queueEnabled && ($request['queue'] ?? false));
    }

    private function processSync(int $fd, array $request): void
    {
        try {
            $result = $this->converter->convertSync(
                $request['file_path'],
                $request['output_format'],
                $request['file_content'] ?? null,
                $request['output_path'] ?? null,
                $request['mode'] ?? 'stream'
            );

            $this->sendResponse($fd, [
                'status' => 'success',
                'result' => $result
            ]);
        } catch (\Throwable $e) {
            $this->sendError($fd, $e->getMessage());
        }
    }

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

    private function processTask(array $taskData): array
    {
        try {
            $result = $this->converter->convertSync(
                $taskData['request']['file_path'],
                $taskData['request']['output_format'],
                $taskData['request']['file_content'] ?? null,
                $taskData['request']['output_path'] ?? null,
                $taskData['request']['mode'] ?? 'stream'
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

    private function sendResponse(int $fd, array $response): void
    {
        $this->server->send($fd, json_encode($response));
    }

    private function sendError(int $fd, string $message): void
    {
        $this->sendResponse($fd, [
            'status' => 'error',
            'message' => $message
        ]);
    }

    public function getResult(string $taskId): ?array
    {
        if ($this->queueEnabled) {
            return $this->queue->getResult($taskId);
        }
        return null;
    }
}