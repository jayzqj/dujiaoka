<?php

namespace App\Console\Commands;

use App\Models\Procurement;
use App\Service\ProcurementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcurementWork extends Command
{
    protected $signature = 'procurement:work {--once : Process one batch and exit}';
    protected $description = 'Process durable card procurement records without resubmitting purchases';

    private $stopping = false;

    public function handle(ProcurementService $service)
    {
        if ($this->option('once')) {
            $this->processBatch($service);
            return 0;
        }
        $this->listenForStopSignals();
        while (!$this->stopping) {
            try {
                $processed = $this->processBatch($service);
            } catch (Throwable $exception) {
                // Durable states make a retry safe; exiting during a database outage can leave Supervisor in FATAL.
                // Messages are not logged because query bindings may contain card secrets.
                Log::error('procurement_worker_iteration_failed', [
                    'exception' => get_class($exception), 'code' => $exception->getCode(),
                ]);
                sleep(10);
                continue;
            }
            if (!$processed) {
                sleep(2);
            }
        }
        return 0;
    }

    private function processBatch(ProcurementService $service): bool
    {
        $service->recover();
        $ids = Procurement::where('state', 'pending')->orderBy('id')->limit(20)->pluck('id');
        foreach ($ids as $id) {
            // A stop request lets the current purchase finish instead of leaving it unknown.
            if ($this->stopping) {
                break;
            }
            $service->process($id);
        }
        return $ids->isNotEmpty();
    }

    private function listenForStopSignals(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function () {
                $this->stopping = true;
            });
        }
    }
}
