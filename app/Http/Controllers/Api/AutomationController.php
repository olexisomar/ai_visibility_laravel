<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAutomationRun;
use App\Models\AutomationRun;
use App\Models\AutomationSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutomationController extends Controller
{
    /**
     * Get automation settings
     */
    public function getSettings()
    {
        $settings = AutomationSetting::get();
        
        return response()->json([
            'settings' => $settings,
            'runs_today' => AutomationRun::today()->count(),
            'max_runs_per_day' => $settings->max_runs_per_day,
        ]);
    }

    /**
     * Update automation settings
     */
    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'schedule' => 'required|in:paused,weekly',
            'default_source' => 'required|in:gpt,google_aio,all',
            'schedule_day' => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'schedule_time' => 'required|date_format:H:i',
            'max_runs_per_day' => 'required|integer|min:1|max:50',
            'notifications_enabled' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $settings = AutomationSetting::get();
        $settings->update($request->only([
            'schedule',
            'default_source',
            'schedule_day',
            'schedule_time',
            'max_runs_per_day',
            'notifications_enabled',
        ]));

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $settings,
        ]);
    }

    /**
     * Trigger a manual run
     */
    public function runOnce(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source' => 'required|in:gpt,google_aio,all',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $settings = AutomationSetting::get();

        // Check daily limit
        if (!$settings->canRunToday()) {
            return response()->json([
                'error' => 'Daily run limit reached',
                'max_runs' => $settings->max_runs_per_day,
            ], 429);
        }

        // Create run record
        $run = AutomationRun::create([
            'trigger_type' => 'manual',
            'source' => $request->source,
            'status' => 'pending',
            'triggered_by' => $request->user()->email ?? 'admin', // TODO: Get actual user
        ]);

        // Dispatch job
        ProcessAutomationRun::dispatch($run);

        return response()->json([
            'message' => 'Run started successfully',
            'run' => $run,
        ]);
    }

    /**
     * Get recent runs
     */
    public function getRecentRuns(Request $request)
    {
        $limit = min($request->input('limit', 20), 100);
        
        $runs = AutomationRun::recent($limit)->get();

        return response()->json([
            'runs' => $runs,
        ]);
    }

    /**
     * Get single run status
     */
    public function getRunStatus($id)
    {
        $run = AutomationRun::findOrFail($id);

        return response()->json([
            'run' => $run,
        ]);
    }

    /**
     * Get budget statistics
     */
    public function getBudgetStats(Request $request)
    {
        $settings = AutomationSetting::get();
        
        // Calculate this month's actual cost
        $thisMonth = now()->startOfMonth();
        
        $mentions = DB::table('mentions as m')
            ->join('responses as r', 'm.response_id', '=', 'r.id')
            ->whereBetween('r.created_at', [$thisMonth, now()])
            ->whereNotNull('m.sentiment')
            ->count();
        
        $runs = DB::table('automation_runs')
            ->whereBetween('created_at', [$thisMonth, now()])
            ->where('status', 'completed')
            ->count();
        
        // Cost calculation
        $avgTokensPerMention = 210; // ~200 input + 10 output
        $costPerToken = 0.00000015; // GPT-4o-mini
        $sentimentCost = $mentions * $avgTokensPerMention * $costPerToken;
        
        // Estimate prompt cost (rough estimate)
        $avgPromptsPerRun = 50;
        $avgTokensPerPrompt = 700;
        $promptCost = $runs * $avgPromptsPerRun * $avgTokensPerPrompt * $costPerToken;
        
        $totalCost = $sentimentCost + $promptCost;
        $percentUsed = $settings->monthly_budget > 0 
            ? ($totalCost / $settings->monthly_budget) * 100 
            : 0;
        
        return response()->json([
            'budget' => [
                'monthly_budget' => (float) $settings->monthly_budget,
                'total_spent' => round($totalCost, 4),
                'sentiment_cost' => round($sentimentCost, 4),
                'prompt_cost' => round($promptCost, 4),
                'percent_used' => round($percentUsed, 2),
                'remaining' => round($settings->monthly_budget - $totalCost, 4),
                'mentions_analyzed' => $mentions,
                'runs_completed' => $runs,
            ],
            'projections' => [
                'days_elapsed' => now()->day,
                'days_in_month' => now()->daysInMonth,
                'projected_monthly' => round(($totalCost / now()->day) * now()->daysInMonth, 2),
            ]
        ]);
    }

    /**
     * Update budget settings
     */
    public function updateBudget(Request $request)
    {
        $validated = $request->validate([
            'monthly_budget' => 'required|numeric|min:0|max:10000',
        ]);
        
        $settings = AutomationSetting::get();
        $settings->monthly_budget = $validated['monthly_budget'];
        $settings->save();
        
        return response()->json([
            'success' => true,
            'monthly_budget' => (float) $settings->monthly_budget,
        ]);
    }
}