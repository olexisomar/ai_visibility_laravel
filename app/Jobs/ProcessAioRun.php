<?php

namespace App\Jobs;

use App\Services\AIOService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAioRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    protected string $hl;
    protected string $gl;
    protected string $location;
    protected int $offset;
    protected int $accountId;

    public function __construct(string $hl, string $gl, string $location, int $offset, int $accountId)
    {
        $this->hl = $hl;
        $this->gl = $gl;
        $this->location = $location;
        $this->offset = $offset;
        $this->accountId = $accountId;
    }

    public function handle(AIOService $aioService): void
    {
        try {
            // Service creates its own run record
            $result = $aioService->runAIOMonitoring(
                $this->hl,
                $this->gl,
                $this->location,
                $this->offset,
                $this->accountId
            );

            Log::info('AIO run completed', [
                'run_id' => $result['run_id'],
                'processed' => $result['processed'],
            ]);

        } catch (\Exception $e) {
            Log::error('AIO run failed: ' . $e->getMessage());
            throw $e;
        }
    }
}