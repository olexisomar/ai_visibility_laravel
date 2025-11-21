<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use App\Models\AutomationSetting;
use App\Models\AutomationRun;

// ==================== WEEKLY AUTOMATION CHECK ====================
// Check automation settings every hour and run if it's time
Schedule::call(function () {
    $settings = AutomationSetting::first();
    
    $now = now();
    $scheduledTime = \Carbon\Carbon::parse($settings->schedule_time);
    
    // Always log current state
    Log::info('🔍 Automation check', [
        'now' => $now->format('Y-m-d H:i:s'),
        'today_day' => $now->dayOfWeek,
        'target_day' => $settings->schedule_day,
        'current_hour' => $now->hour,
        'current_minute' => $now->minute,
        'target_hour' => $scheduledTime->hour,
        'target_minute' => $scheduledTime->minute,
        'is_paused' => $settings->isPaused(),
    ]);
    
    // Skip if paused
    if ($settings->isPaused()) {
        return;
    }
    
    // Check day
    $dayMap = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];
    
    $targetDay = $dayMap[$settings->schedule_day] ?? 1;
    if ($now->dayOfWeek !== $targetDay) {
        return;
    }
    
    // Check time
    if ($now->hour !== $scheduledTime->hour || $now->minute !== $scheduledTime->minute) {
        return;
    }
    
    // Check already ran
    $alreadyRanToday = AutomationRun::whereDate('created_at', today())
        ->where('trigger_type', 'scheduled')
        ->exists();
    
    if ($alreadyRanToday) {
        Log::info('⏭️ Already ran today');
        return;
    }
    
    Log::info('🚀 Triggering automation!');
    Artisan::call('automation:run-weekly');
    
})->everyMinute()->name('check-automation');

// ==================== STUCK RUN DETECTOR ====================
// Check for stuck automation runs every 15 minutes
Schedule::command('automation:check-stuck --timeout=7200')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('check-stuck-runs');

// ==================== NOTIFICATION CLEANUP ====================
// Clean old notifications daily at 2am
Schedule::command('notifications:clean --days=30')
    ->dailyAt('02:00')
    ->name('clean-old-notifications');