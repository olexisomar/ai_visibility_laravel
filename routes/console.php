<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use App\Models\AutomationSetting;
use App\Models\AutomationRun;

// Check automation settings every hour and run if it's time
Schedule::call(function () {
    Log::info('🔍 Automation check started', [
        'time' => now()->format('Y-m-d H:i:s'),
        'day' => now()->englishDayOfWeek,
        'hour' => now()->hour,
    ]);
    
    $settings = AutomationSetting::get();
    
    // Skip if paused
    if ($settings->isPaused()) {
        Log::info('⏸️  Automation check: Paused');
        return;
    }

    // Check if today is the scheduled day
    $dayMap = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];
    
    $targetDay = $dayMap[$settings->schedule_day] ?? 1;
    $today = now()->dayOfWeek;
    
    if ($today !== $targetDay) {
        Log::debug("❌ Automation check: Wrong day", [
            'today' => now()->englishDayOfWeek,
            'target' => $settings->schedule_day,
        ]);
        return;
    }

    // Check if current time matches scheduled time (within 1 hour window)
    $scheduledTime = \Carbon\Carbon::parse($settings->schedule_time);
    $now = now();
    
    if ($now->hour !== $scheduledTime->hour) {
        Log::debug("❌ Automation check: Wrong hour", [
            'current_hour' => $now->hour,
            'target_hour' => $scheduledTime->hour,
        ]);
        return;
    }

    // Check if we already ran today
    $alreadyRanToday = AutomationRun::whereDate('created_at', today())
        ->where('trigger_type', 'scheduled')
        ->exists();
    
    if ($alreadyRanToday) {
        Log::info('⏭️  Automation check: Already ran today');
        return;
    }

    // Run automation!
    Log::info('🚀 Triggering weekly automation!', [
        'day' => $settings->schedule_day,
        'time' => $settings->schedule_time,
    ]);
    
    Artisan::call('automation:run-weekly');
    
})->hourly()->name('check-automation');