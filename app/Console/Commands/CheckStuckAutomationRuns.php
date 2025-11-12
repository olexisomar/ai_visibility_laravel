<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckStuckAutomationRuns extends Command
{
    protected $signature = 'automation:check-stuck {--timeout=7200 : Seconds before marking as stuck}';
    protected $description = 'Check for automation runs stuck in running status and mark them as failed';

    public function handle()
    {
        $timeoutSeconds = (int) $this->option('timeout');
        $cutoffTime = now()->subSeconds($timeoutSeconds);
        
        // Find stuck runs
        $stuckRuns = AutomationRun::where('status', 'running')
            ->where('started_at', '<', $cutoffTime)
            ->get();
        
        if ($stuckRuns->isEmpty()) {
            $this->info('✓ No stuck runs found');
            return 0;
        }
        
        foreach ($stuckRuns as $run) {
            $durationHours = round(now()->diffInMinutes($run->started_at) / 60, 2);
            
            // Get progress from related runs (if automation_run_id column exists)
            $gptProcessed = 0;
            $aioProcessed = 0;
            
            try {
                $gptRun = DB::table('runs')
                    ->where('automation_run_id', $run->id)
                    ->where('model', 'like', '%gpt%')
                    ->first();
                
                $aioRun = DB::table('runs')
                    ->where('automation_run_id', $run->id)
                    ->where('model', 'like', '%gemini%')
                    ->first();
                
                if ($gptRun) {
                    $gptProcessed = DB::table('responses')->where('run_id', $gptRun->id)->count();
                }
                
                if ($aioRun) {
                    $aioProcessed = DB::table('responses')->where('run_id', $aioRun->id)->count();
                }
            } catch (\Exception $e) {
                // Column might not exist - skip progress tracking
                $this->warn('Could not get progress (automation_run_id column may not exist)');
            }
            
            $totalProcessed = $gptProcessed + $aioProcessed;
            
            // Build error message
            $errorMessage = "Run exceeded {$timeoutSeconds}s timeout after {$durationHours}h.";
            if ($totalProcessed > 0) {
                $errorMessage .= " Processed {$totalProcessed} prompts (GPT: {$gptProcessed}, AIO: {$aioProcessed}) before timeout.";
            }
            
            // Mark as failed
            $run->markFailed($errorMessage);
            
            // Also mark related runs as failed (if they exist)
            try {
                DB::table('runs')
                    ->where('automation_run_id', $run->id)
                    ->where('status', 'running')
                    ->update([
                        'status' => 'failed',
                        'finished_at' => now(),
                    ]);
            } catch (\Exception $e) {
                // automation_run_id column might not exist - skip
            }
            
            Log::warning('Marked stuck automation run as failed', [
                'automation_run_id' => $run->id,
                'account_id' => $run->account_id,
                'duration_hours' => $durationHours,
                'processed' => $totalProcessed,
            ]);
            
            $this->warn("✗ Run #{$run->id} (Account: {$run->account_id}) marked as failed");
            $this->warn("  Stuck for {$durationHours}h, processed {$totalProcessed} prompts");
        }
        
        $this->info("Marked {$stuckRuns->count()} stuck runs as failed");
        
        return 0;
    }
}