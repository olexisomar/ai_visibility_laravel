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
use Illuminate\Support\Facades\DB;

class ProcessAutomationRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours max (increased from 1 hour)
    public $tries = 1; // Don't retry on failure

    public function __construct(
        public AutomationRun $run
    ) {}

    public function handle(): void
    {
        // Remove PHP execution time limits completely
        set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1024M');
        
        try {
            $this->run->markStarted();
            
            Log::info('Automation run started', [
                'automation_run_id' => $this->run->id,
                'source' => $this->run->source,
                'trigger_type' => $this->run->trigger_type,
            ]);

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
                    // Run GPT first
                    try {
                        [$p1, $m1] = $this->runGPT();
                        $promptsProcessed += $p1;
                        $newMentions += $m1;
                        
                        // Update progress after GPT completes
                        $this->run->update([
                            'prompts_processed' => $promptsProcessed,
                            'new_mentions' => $newMentions,
                        ]);
                        
                        Log::info('GPT phase completed, starting AIO', [
                            'automation_run_id' => $this->run->id,
                            'gpt_prompts' => $p1,
                            'gpt_mentions' => $m1,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('GPT run failed in automation', [
                            'automation_run_id' => $this->run->id,
                            'error' => $e->getMessage(),
                        ]);
                        // Continue to AIO even if GPT fails
                    }
                    
                    // Run AIO second
                    try {
                        [$p2, $m2] = $this->runGoogleAIO();
                        $promptsProcessed += $p2;
                        $newMentions += $m2;
                        
                        Log::info('AIO phase completed', [
                            'automation_run_id' => $this->run->id,
                            'aio_prompts' => $p2,
                            'aio_mentions' => $m2,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('AIO run failed in automation', [
                            'automation_run_id' => $this->run->id,
                            'error' => $e->getMessage(),
                        ]);
                        // If GPT succeeded but AIO failed, still mark as completed
                        if ($promptsProcessed > 0) {
                            Log::warning('AIO failed but GPT succeeded, marking as completed', [
                                'automation_run_id' => $this->run->id,
                                'total_processed' => $promptsProcessed,
                            ]);
                        } else {
                            throw $e; // Both failed
                        }
                    }
                    break;
            }

            $this->run->markCompleted($promptsProcessed, $newMentions);
            
            Log::info('Automation run completed successfully', [
                'automation_run_id' => $this->run->id,
                'source' => $this->run->source,
                'prompts_processed' => $promptsProcessed,
                'new_mentions' => $newMentions,
                'duration' => $this->run->duration_seconds . 's',
            ]);
            
        } catch (\Exception $e) {
            $this->run->markFailed($e->getMessage());
            
            Log::error('Automation run failed', [
                'automation_run_id' => $this->run->id,
                'source' => $this->run->source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    private function runGPT(): array
    {
        try {
            Log::info('Starting GPT monitoring', [
                'automation_run_id' => $this->run->id,
            ]);

            $monitoring = app(MonitoringService::class);
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');
            $temp = (float) env('TEMPERATURE', 0.2);

            // Call monitoring service
            $result = $monitoring->runMonitoring($model, $temp, 0, $this->run->account_id);
            
            $processed = $result['processed'] ?? 0;
            
            // Calculate mentions from the run_id
            $runId = $result['run_id'] ?? null;
            $mentions = 0;
            
            if ($runId) {
                $mentions = DB::table('mentions')
                    ->join('responses', 'mentions.response_id', '=', 'responses.id')
                    ->where('responses.run_id', $runId)
                    ->count();
                
                // Link this run to automation_run if column exists
                try {
                    DB::table('runs')
                        ->where('id', $runId)
                        ->update(['automation_run_id' => $this->run->id]);
                } catch (\Exception $e) {
                    // Column might not exist yet - ignore
                    Log::debug('Could not link run to automation_run (column may not exist)', [
                        'run_id' => $runId,
                        'automation_run_id' => $this->run->id,
                    ]);
                }
            }

            Log::info('GPT monitoring completed', [
                'automation_run_id' => $this->run->id,
                'run_id' => $runId,
                'processed' => $processed,
                'mentions' => $mentions,
            ]);

            return [$processed, $mentions];
            
        } catch (\Exception $e) {
            Log::error('GPT monitoring failed', [
                'automation_run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function runGoogleAIO(): array
    {
        try {
            Log::info('Starting AIO monitoring', [
                'automation_run_id' => $this->run->id,
            ]);

            $aioService = app(AIOService::class);
            $hl = env('SERPAPI_HL', 'en');
            $gl = env('SERPAPI_GL', 'us');
            $location = env('SERPAPI_LOCATION', 'United States');

            // Call AIO service
            $result = $aioService->runAIOMonitoring($hl, $gl, $location, 0, $this->run->account_id);
            
            $processed = $result['processed'] ?? 0;
            
            // Calculate mentions from the run_id
            $runId = $result['run_id'] ?? null;
            $mentions = 0;
            
            if ($runId) {
                $mentions = DB::table('mentions')
                    ->join('responses', 'mentions.response_id', '=', 'responses.id')
                    ->where('responses.run_id', $runId)
                    ->count();
                
                // Link this run to automation_run if column exists
                try {
                    DB::table('runs')
                        ->where('id', $runId)
                        ->update(['automation_run_id' => $this->run->id]);
                } catch (\Exception $e) {
                    // Column might not exist yet - ignore
                    Log::debug('Could not link run to automation_run (column may not exist)', [
                        'run_id' => $runId,
                        'automation_run_id' => $this->run->id,
                    ]);
                }
            }

            Log::info('AIO monitoring completed', [
                'automation_run_id' => $this->run->id,
                'run_id' => $runId,
                'processed' => $processed,
                'mentions' => $mentions,
            ]);

            return [$processed, $mentions];
            
        } catch (\Exception $e) {
            Log::error('AIO monitoring failed', [
                'automation_run_id' => $this->run->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}