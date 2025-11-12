<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAutomationRun;
use App\Models\AutomationRun;
use App\Models\AutomationSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunWeeklyAutomation extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'automation:run-weekly';

    /**
     * The console command description.
     */
    protected $description = 'Run weekly automation based on settings';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $settings = AutomationSetting::get();

        // Check if automation is paused
        if ($settings->isPaused()) {
            $this->info('⏸️  Automation is paused. Skipping run.');
            Log::info('Weekly automation skipped - paused');
            return 0;
        }

        // Check daily limit
        if (!$settings->canRunToday()) {
            $this->warn('⚠️  Daily run limit reached. Skipping run.');
            Log::warning('Weekly automation skipped - daily limit reached', [
                'max_runs' => $settings->max_runs_per_day,
            ]);
            return 1;
        }

        $this->info('🤖 Starting weekly automation...');
        $this->info("📅 Schedule: {$settings->schedule}");
        $this->info("🎯 Source: {$settings->default_source}");

        // Create run record
        $run = AutomationRun::create([
            'account_id' => $settings->account_id,
            'trigger_type' => 'scheduled',
            'source' => $settings->default_source,
            'status' => 'pending',
            'triggered_by' => 'scheduler',
        ]);

        $this->info("✅ Created automation run #{$run->id}");

        // Dispatch job
        ProcessAutomationRun::dispatch($run);

        $this->info('🚀 Job dispatched successfully!');
        
        Log::info('Weekly automation triggered', [
            'run_id' => $run->id,
            'source' => $settings->default_source,
        ]);

        return 0;
    }
}