<?php

namespace Tabula17\Mundae\Odf\Mutatis\Worker;

use Swoole\Process;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\ServerHealthMonitor;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\UnoserverLoadBalancer;
use Tabula17\Satelles\Utilis\Console\VerboseTrait;
use Tabula17\Satelles\Utilis\Queue\QueueInterface;
use Tabula17\Satelles\Utilis\Queue\RedisQueue;

/**
 * Worker para procesamiento asíncrono de conversiones
 *
 * Procesa las tareas de conversión en segundo plano, consumiendo
 * solicitudes desde una cola de Redis y ejecutando las conversiones
 * utilizando instancias unoserver balanceadas.
 *
 * @package Tabula17\Mundae\Odf\Mutatis\Worker
 * @property ServerHealthMonitor $healthMonitor
 */
class ConversionWorker
{
    use VerboseTrait;

    /**
     * @var UnoserverLoadBalancer Gestor de balanceo de carga
     */
    private UnoserverLoadBalancer $converter;

    /**
     * @var QueueInterface Sistema de cola
     */
    private QueueInterface $queue;

    /**
     * @var bool Bandera para control de apagado
     */
    private bool $shouldStop = false;

    /**
     * @var int Límite de conversiones simultáneas
     */
    private int $concurrency;

    /**
     * @var ServerHealthMonitor Monitor de salud de servidores
     */
    private ServerHealthMonitor $healthMonitor;

    /**
     * @var int Tiempo límite para conversiones
     */
    private int $timeout;

    /**
     * Constructor del worker
     *
     * @param QueueInterface $queue Sistema de cola para procesar
     * @param ServerHealthMonitor $healthMonitor Monitor de salud de servidores
     * @param int $concurrency Número máximo de conversiones simultáneas
     * @param int $timeout Tiempo límite para conversiones en segundos
     */
    public function __construct(QueueInterface $queue, ServerHealthMonitor $healthMonitor, int $concurrency = 4, int $timeout = 10, private readonly int $verbose = self::ERROR)
    {
        $this->queue = $queue;
        $this->timeout = $timeout;
        $this->healthMonitor = $healthMonitor;
        $this->concurrency = $concurrency;
    }

    /**
     * Inicia el worker y comienza a procesar tareas
     *
     * @return void
     */
    public function start(): void
    {
        // Manejar señales para shutdown graceful
        Process::signal(SIGTERM, function () {
            $this->shouldStop = true;
            $this->notice( "Recibida señal de terminación, finalizando worker...");
        });

        $this->notice( "Worker iniciado. Esperando tareas...");

        while (!$this->shouldStop) {
            $this->processNextTask();
        }
        $this->healthMonitor->startMonitoring();
        $this->notice( "Worker detenido correctamente");
    }

    /**
     * Procesa la siguiente tarea en la cola
     *
     * @return void
     */
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
            $this->debug( "Procesando tarea ID: {$task['task_id']}");

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
            $this->error( "Error procesando tarea ID: {$task['task_id']}: " . $e->getMessage());
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

    /**
     * Inicializa el conversor si aún no está inicializado
     *
     * @return void
     */
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

    /**
     * Almacena el resultado de una tarea en la cola
     *
     * @param string $taskId ID de la tarea
     * @param array $result Resultado de la conversión
     * @return void
     */
    private function storeResult(string $taskId, array $result): void
    {
        $this->queue->pushResult(
            taskId: $taskId,
            result: $result
        );
    }

    /**
     * Detiene el worker de forma segura
     *
     * @return void
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        if (isset($this->converter)) {
            $this->converter->stop();
        }
        $this->notice( "Worker detenido correctamente");
    }

    /**
     * Obtiene el monitor de salud actual
     *
     * @return ServerHealthMonitor
     */
    public function getHealthMonitor(): ServerHealthMonitor
    {
        return $this->healthMonitor;
    }

    private function isVerbose(int $level): bool
    {
        $this->verboseIcon = '🗺️';
        return $level >= $this->verbose;
    }
}
// Ejecutar worker
//$worker = new ConversionWorker();
//$worker->start();
