<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToAccount;

class AutomationSetting extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
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
     * Get settings for current account (or create if missing)
     */
    public static function getCurrentOrCreate()
    {
        $accountId = session('account_id');
        
        return self::firstOrCreate(
            ['account_id' => $accountId],
            [
                'schedule' => 'weekly',
                'default_source' => 'all',
                'schedule_day' => 'monday',
                'schedule_time' => '09:00',
                'max_runs_per_day' => 10,
                'notifications_enabled' => false,
                'notification_email' => null,
            ]
        );
    }

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