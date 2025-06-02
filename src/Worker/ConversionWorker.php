<?php

namespace Tabula17\Mundae\Odf\Mutatis\Worker;

use Swoole\Process;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;
use Tabula17\Satelles\Utilis\Queue\QueueInterface;
use Tabula17\Satelles\Utilis\Queue\RedisQueue;

/**
 * @property ServerHealthMonitor $healthMonitor
 */
class ConversionWorker
{
    private UnoserverLoadBalancer $converter;
    private QueueInterface $queue;
    private bool $shouldStop = false;
    private int $concurrency;
    private ServerHealthMonitor $healthMonitor;
    private int $timeout;

    public function __construct(QueueInterface $queue, ServerHealthMonitor $healthMonitor, int $concurrency = 4, int $timeout = 10)
    {
        $this->queue = $queue;
        $this->timeout = $timeout;
        $this->healthMonitor = $healthMonitor;
        $this->concurrency = $concurrency;
    }

    public function start(): void
    {
        // Manejar señales para shutdown graceful
        Process::signal(SIGTERM, function () {
            $this->shouldStop = true;
            echo "Recibida señal de terminación, finalizando worker...\n";
        });

        echo "Worker iniciado. Esperando tareas...\n";

        while (!$this->shouldStop) {
            $this->processNextTask();
        }
        $this->healthMonitor->startMonitoring();
        echo "Worker detenido correctamente\n";
    }

    private function processNextTask(): void
    {
        $task = $this->queue->pop();

        if (!$task) {
            sleep(1); // Espera breve si no hay tareas
            return;
        }

        // Inicializar el conversor solo cuando sea necesario
        $this->initializeConverter();

        try {
            echo "Procesando tarea ID: {$task['task_id']}\n";

            $result = $this->converter->convertSync(
                $task['file_path'],
                $task['output_format'],
                $task['file_content'] ?? null,
                $task['output_path'] ?? null,
                $task['mode'] ?? 'stream'
            );

            $this->storeResult($task['task_id'], [
                'status' => 'completed',
                'result' => $result,
                'completed_at' => time()
            ]);
        } catch (\Throwable $e) {
            $this->storeResult($task['task_id'], [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'failed_at' => time()
            ]);
        } finally {
            $this->queue->ack($task['task_id']);
            $this->converter->stop();
            // Liberar recursos del conversor
            unset($this->converter);
        }
    }

    private function initializeConverter(): void
    {
        if (!isset($this->converter)) {
            $this->converter = new UnoserverLoadBalancer(
                healthMonitor: $this->healthMonitor,
                concurrency: $this->concurrency ?? 4,
                timeout: $this->timeout ?? 10
            );
            $this->converter->start();
        }
    }

    private function storeResult(string $taskId, array $result): void
    {
        $this->queue->pushResult(
            taskId: $taskId,
            result: $result
        );
    }
    public function stop(): void
    {
        $this->shouldStop = true;
        if (isset($this->converter)) {
            $this->converter->stop();
        }
        echo "Worker detenido correctamente\n";
    }
    public function getHealthMonitor(): ServerHealthMonitor
    {
        return $this->healthMonitor;
    }

}
// Ejecutar worker
//$worker = new ConversionWorker();
//$worker->start();