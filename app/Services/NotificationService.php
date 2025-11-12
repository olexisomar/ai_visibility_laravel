<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send notification for completed run
     */
    public function notifyRunCompleted(int $runId, array $metrics): void
    {
        try {
            // Check if notifications are enabled
            $settings = DB::table('automation_settings')->first();
            
            if (!$settings || !$settings->notifications_enabled) {
                return; // Notifications disabled
            }
            
            // Get run details
            $run = DB::table('runs')->where('id', $runId)->first();
            
            if (!$run) {
                return;
            }
            
            // Calculate duration
            $duration = $this->formatDuration($run->started_at, $run->finished_at);
            
            // Build message
            $title = "{$run->model} Run #{$runId} Completed";
            $message = $this->buildRunCompletedMessage($run, $metrics, $duration);
            
            // Save in-app notification
            $notificationId = DB::table('notifications')->insertGetId([
                'account_id' => $run->account_id,
                'type' => 'run_completed',
                'title' => $title,
                'message' => $message,
                'data' => json_encode([
                    'run_id' => $runId,
                    'model' => $run->model,
                    'metrics' => $metrics,
                ]),
                'is_read' => false,
                'created_at' => now(),
            ]);
            
            // Send email if configured
            if ($settings->notification_email) {
                $this->sendEmail($settings->notification_email, $title, $message, $run, $metrics);
            }
            
            Log::info('Run notification sent', [
                'run_id' => $runId,
                'notification_id' => $notificationId,
                'account_id' => $run->account_id,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Notification error: ' . $e->getMessage());
            // Don't throw - notifications shouldn't break the run
        }
    }
    
    /**
     * Build run completed message
     */
    private function buildRunCompletedMessage($run, array $metrics, string $duration): string
    {
        $prompts = $metrics['prompts_processed'] ?? 0;
        $mentions = $metrics['mentions_found'] ?? 0;
        $brands = $metrics['brands_mentioned'] ?? 0;
        
        return "Your {$run->model} monitoring run has completed.\n\n" .
               "📊 Results:\n" .
               "• Prompts processed: {$prompts}\n" .
               "• Brand mentions: {$mentions}\n" .
               "• Brands found: {$brands}\n" .
               "• Duration: {$duration}\n\n" .
               "View detailed results in your dashboard.";
    }
    
    /**
     * Send email notification
     */
    private function sendEmail(string $to, string $title, string $message, $run, array $metrics): void
    {
        try {
            Mail::send([], [], function ($mail) use ($to, $title, $message, $run, $metrics) {
                $mail->to($to)
                     ->subject($title)
                     ->html($this->buildEmailHtml($title, $message, $run, $metrics));
            });
            
            Log::info('Email notification sent', ['to' => $to]);
            
        } catch (\Exception $e) {
            Log::error('Email send error: ' . $e->getMessage());
        }
    }
    
    /**
     * Build HTML email
     */
    private function buildEmailHtml(string $title, string $message, $run, array $metrics): string
    {
        $prompts = $metrics['prompts_processed'] ?? 0;
        $mentions = $metrics['mentions_found'] ?? 0;
        $brands = $metrics['brands_mentioned'] ?? 0;
        $dashboardUrl = env('APP_URL', 'http://localhost');
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #3b82f6; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 20px; border: 1px solid #e5e7eb; }
        .metric { background: white; padding: 15px; margin: 10px 0; border-radius: 6px; border-left: 4px solid #3b82f6; }
        .metric-label { font-size: 12px; color: #6b7280; text-transform: uppercase; }
        .metric-value { font-size: 24px; font-weight: bold; color: #1f2937; }
        .button { display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin-top: 20px; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 style="margin: 0;">🔔 {$title}</h1>
        </div>
        <div class="content">
            <p>Your <strong>{$run->model}</strong> monitoring run has completed successfully.</p>
            
            <div class="metric">
                <div class="metric-label">Prompts Processed</div>
                <div class="metric-value">{$prompts}</div>
            </div>
            
            <div class="metric">
                <div class="metric-label">Brand Mentions Found</div>
                <div class="metric-value">{$mentions}</div>
            </div>
            
            <div class="metric">
                <div class="metric-label">Unique Brands</div>
                <div class="metric-value">{$brands}</div>
            </div>
            
            <a href="{$dashboardUrl}" class="button">View Dashboard</a>
        </div>
        <div class="footer">
            <p>AI Visibility Tracker | Automated Monitoring System</p>
            <p>Run ID: #{$run->id} | {$run->finished_at}</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Format duration between timestamps
     */
    private function formatDuration(?string $start, ?string $end): string
    {
        if (!$start || !$end) {
            return 'Unknown';
        }
        
        try {
            $startTime = new \DateTime($start);
            $endTime = new \DateTime($end);
            $diff = $startTime->diff($endTime);
            
            if ($diff->h > 0) {
                return $diff->h . 'h ' . $diff->i . 'm';
            } elseif ($diff->i > 0) {
                return $diff->i . 'm ' . $diff->s . 's';
            } else {
                return $diff->s . 's';
            }
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount(?int $accountId = null): int
    {
        $query = DB::table('notifications')
            ->where('is_read', false);
        
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        
        return $query->count();
    }
    
    /**
     * Get recent notifications
     */
    public function getRecent(int $limit = 10, ?int $accountId = null): array
    {
        $query = DB::table('notifications')
            ->orderBy('created_at', 'desc')
            ->limit($limit);
        
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        
        return $query->get()->toArray();
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead(int $id, ?int $accountId = null): bool
    {
        $query = DB::table('notifications')
            ->where('id', $id);
        
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        
        return $query->update(['is_read' => true]) > 0;
    }
    
    /**
     * Mark all as read
     */
    public function markAllAsRead(?int $accountId = null): int
    {
        $query = DB::table('notifications')
            ->where('is_read', false);
        
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        
        return $query->update(['is_read' => true]);
    }

    /**
     * Delete a specific notification
     */
    public function delete(int $id, ?int $accountId = null): bool
    {
        $query = DB::table('notifications')
            ->where('id', $id);
        
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        
        return $query->delete() > 0;
    }

    /**
     * Clear all notifications for account
     */
    public function clearAll(?int $accountId = null): int
    {
        $query = DB::table('notifications');
        
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        
        return $query->delete();
    }

    /**
     * Clear only read notifications for account
     */
    public function clearRead(?int $accountId = null): int
    {
        $query = DB::table('notifications')
            ->where('is_read', true);
        
        if ($accountId) {
            $query->where('account_id', $accountId);
        }
        
        return $query->delete();
    }

    /**
     * Auto-delete old notifications (older than 30 days)
     */
    public function deleteOldNotifications(int $daysOld = 30): int
    {
        $cutoffDate = now()->subDays($daysOld);
        
        return DB::table('notifications')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }
}