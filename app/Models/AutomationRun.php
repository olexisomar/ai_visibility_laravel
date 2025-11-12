<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToAccount;

class AutomationRun extends Model
{
    use BelongsToAccount;
    
    protected $fillable = [
        'account_id',
        'trigger_type',
        'source',
        'status',
        'prompts_processed',
        'new_mentions',
        'error_message',
        'started_at',
        'completed_at',
        'duration_seconds',
        'triggered_by',
    ];
    
    protected $casts = [
        'prompts_processed' => 'integer',
        'new_mentions' => 'integer',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
    
    /**
     * Scope: Get recent runs
     */
    public function scopeRecent($query, $limit = 10)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
    
    /**
     * Scope: Get today's runs
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', now()->format('Y-m-d'));
    }
    
    /**
     * Mark run as started
     */
    public function markStarted()
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }
    
    /**
     * Mark run as completed
     */
    public function markCompleted(int $promptsProcessed, int $newMentions)
    {
        // FIX: Calculate duration correctly (started_at -> now, not now -> started_at)
        $duration = $this->started_at ? $this->started_at->diffInSeconds(now()) : null;
        
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'prompts_processed' => $promptsProcessed,
            'new_mentions' => $newMentions,
            'duration_seconds' => $duration,
        ]);
    }
    
    /**
     * Mark run as failed
     */
    public function markFailed(string $errorMessage)
    {
        // FIX: Calculate duration correctly (started_at -> now, not now -> started_at)
        $duration = $this->started_at ? $this->started_at->diffInSeconds(now()) : null;
        
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $errorMessage,
            'duration_seconds' => $duration,
        ]);
    }
    
    /**
     * Get status badge color
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            'pending' => 'gray',
            'running' => 'blue',
            'completed' => 'green',
            'failed' => 'red',
        };
    }
    
    /**
     * Get formatted duration
     */
    public function getFormattedDuration(): string
    {
        if (!$this->duration_seconds) return '—';
        
        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;
        
        if ($minutes > 0) {
            return "{$minutes}m {$seconds}s";
        }
        
        return "{$seconds}s";
    }
}