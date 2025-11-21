<?php

namespace App\Jobs;

use App\Services\MonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessGptRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    protected string $model;
    protected float $temp;
    protected int $offset;
    protected int $accountId;

    public function __construct(string $model, float $temp, int $offset, int $accountId)
    {
        $this->model = $model;
        $this->temp = $temp;
        $this->offset = $offset;
        $this->accountId = $accountId;
    }

    public function handle(MonitoringService $monitoring): void
    {
        try {
            // Service creates its own run record
            $result = $monitoring->runMonitoring(
                $this->model,
                $this->temp,
                $this->offset,
                $this->accountId
            );

            Log::info('GPT run completed', [
                'run_id' => $result['run_id'],
                'processed' => $result['processed'],
            ]);

        } catch (\Exception $e) {
            Log::error('GPT run failed: ' . $e->getMessage());
            throw $e;
        }
    }
}