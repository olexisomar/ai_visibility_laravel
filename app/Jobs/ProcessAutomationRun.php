<?php

namespace App\Jobs;

use App\Models\AutomationRun;
use App\Services\MonitoringService;
use App\Services\AIOService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAutomationRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour max

    public function __construct(
        public AutomationRun $run
    ) {}

    public function handle(): void
    {
        try {
            $this->run->markStarted();

            $promptsProcessed = 0;
            $newMentions = 0;

            // Run based on source
            switch ($this->run->source) {
                case 'gpt':
                    [$p, $m] = $this->runGPT();
                    $promptsProcessed += $p;
                    $newMentions += $m;
                    break;

                case 'google_aio':
                    [$p, $m] = $this->runGoogleAIO();
                    $promptsProcessed += $p;
                    $newMentions += $m;
                    break;

                case 'all':
                    [$p1, $m1] = $this->runGPT();
                    [$p2, $m2] = $this->runGoogleAIO();
                    $promptsProcessed = $p1 + $p2;
                    $newMentions = $m1 + $m2;
                    break;
            }

            $this->run->markCompleted($promptsProcessed, $newMentions);

            Log::info('Automation run completed', [
                'run_id' => $this->run->id,
                'source' => $this->run->source,
                'prompts' => $promptsProcessed,
                'mentions' => $newMentions,
            ]);

        } catch (\Exception $e) {
            $this->run->markFailed($e->getMessage());

            Log::error('Automation run failed', [
                'run_id' => $this->run->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function runGPT(): array
    {
        try {
            // Increase execution limits
            set_time_limit(600);
            @ini_set('max_execution_time', '600');

            // Get service
            $monitoring = app(MonitoringService::class);

            // Get default settings from .env
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');
            $temp = (float) env('TEMPERATURE', 0.2);
            $offset = 0;

            Log::info('Starting GPT automation run', [
                'model' => $model,
                'temp' => $temp,
            ]);

            // Call the service directly
            $result = $monitoring->runMonitoring($model, $temp, $offset);

            $processed = $result['processed'] ?? 0;
            $mentions = $result['mentions_found'] ?? 0;

            Log::info('GPT automation run completed', [
                'processed' => $processed,
                'mentions' => $mentions,
            ]);

            return [$processed, $mentions];

        } catch (\Exception $e) {
            Log::error('GPT run failed in automation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function runGoogleAIO(): array
    {
        try {
            // Increase execution limits
            set_time_limit(600);
            @ini_set('max_execution_time', '600');

            // Get service
            $aioService = app(AIOService::class);

            // Get default settings from .env
            $hl = env('SERPAPI_HL', 'en');
            $gl = env('SERPAPI_GL', 'us');
            $location = env('SERPAPI_LOCATION', 'United States');
            $offset = 0;

            Log::info('Starting AIO automation run', [
                'hl' => $hl,
                'gl' => $gl,
                'location' => $location,
            ]);

            // Call the service directly
            $result = $aioService->runAIOMonitoring($hl, $gl, $location, $offset);

            $processed = $result['processed'] ?? 0;
            $mentions = $result['mentions_found'] ?? 0;

            Log::info('AIO automation run completed', [
                'processed' => $processed,
                'mentions' => $mentions,
            ]);

            return [$processed, $mentions];

        } catch (\Exception $e) {
            Log::error('AIO run failed in automation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}