<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationSetting extends Model
{
    protected $fillable = [
        'schedule',
        'default_source',
        'schedule_day',
        'schedule_time',
        'max_runs_per_day',
        'notifications_enabled',
        'notification_email',
    ];

    protected $casts = [
        'notifications_enabled' => 'boolean',
        'max_runs_per_day' => 'integer',
    ];

    /**
     * Get the singleton settings record
     */
    public static function get()
    {
        return self::firstOrCreate([], [
            'schedule' => 'weekly',
            'default_source' => 'all',
            'schedule_day' => 'monday',
            'schedule_time' => '09:00',
            'max_runs_per_day' => 10,
            'notifications_enabled' => false,
            'notification_email' => null,
        ]);
    }

    /**
     * Check if automation is paused
     */
    public function isPaused(): bool
    {
        return $this->schedule === 'paused';
    }

    /**
     * Check if we can run today
     */
    public function canRunToday(): bool
    {
        $today = now()->format('Y-m-d');
        $runsToday = AutomationRun::whereDate('created_at', $today)->count();
        
        return $runsToday < $this->max_runs_per_day;
    }
}