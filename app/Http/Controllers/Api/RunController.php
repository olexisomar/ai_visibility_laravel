<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGptRun;
use App\Jobs\ProcessAioRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunController extends Controller
{
    /**
     * Get all runs
     */
    public function index(Request $request)
    {
        $accountId = session('account_id');
        
        $runs = DB::table('runs')
            ->where('account_id', $accountId)
            ->orderBy('started_at', 'desc')
            ->limit(50)
            ->get();
        
        return response()->json(['rows' => $runs]);
    }
    
    /**
     * Get single run with details
     */
    public function show($id)
    {
        $run = DB::table('runs')->where('id', $id)->first();
        
        if (!$run) {
            return response()->json(['error' => 'Run not found'], 404);
        }
        
        // Get response stats
        $stats = DB::table('responses')
            ->where('run_id', $id)
            ->select([
                DB::raw('COUNT(*) as total_responses'),
                DB::raw('COUNT(DISTINCT prompt_id) as prompts_tested'),
            ])
            ->first();
        
        // Get mention stats
        $mentions = DB::table('mentions')
            ->join('responses', 'mentions.response_id', '=', 'responses.id')
            ->where('responses.run_id', $id)
            ->select([
                DB::raw('COUNT(*) as total_mentions'),
                DB::raw('COUNT(DISTINCT mentions.brand_id) as brands_mentioned'),
            ])
            ->first();
        
        return response()->json([
            'run' => $run,
            'stats' => $stats,
            'mentions' => $mentions,
        ]);
    }
    
    /**
     * Start a new GPT monitoring run (background job)
     */
    public function start(Request $request)
    {
        $user = auth()->user();
        $accountId = session('account_id');
        
        // Check if user can run queries
        if (!$user->canRunQueries($accountId)) {
            return response()->json([
                'error' => 'Unauthorized - only admins can run queries'
            ], 403);
        }
        
        $validated = $request->validate([
            'model' => 'nullable|string',
            'temp' => 'nullable|numeric',
            'offset' => 'nullable|integer',
        ]);
        
        try {
            $model = $validated['model'] ?? config('services.openai.model', 'gpt-4');
            $temp = $validated['temp'] ?? (float)config('services.openai.temperature', 0.7);
            $offset = $validated['offset'] ?? 0;
            
            // Dispatch background job (service creates run record)
            ProcessGptRun::dispatch($model, $temp, $offset, $accountId);
            
            return response()->json([
                'success' => true,
                'message' => 'GPT run started in background',
            ]);
            
        } catch (\Exception $e) {
            Log::error('GPT run dispatch error: ' . $e->getMessage());
            
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Start Google AIO monitoring run (background job)
     */
    public function startAIO(Request $request)
    {
        $user = auth()->user();
        $accountId = session('account_id');
        
        // Check if user can run queries
        if (!$user->canRunQueries($accountId)) {
            return response()->json([
                'error' => 'Unauthorized - only admins can run queries'
            ], 403);
        }
        
        $validated = $request->validate([
            'hl' => 'nullable|string|max:10',
            'gl' => 'nullable|string|max:10',
            'location' => 'nullable|string|max:100',
            'offset' => 'nullable|integer',
        ]);
        
        try {
            $hl = $validated['hl'] ?? config('services.serpapi.hl', 'en');
            $gl = $validated['gl'] ?? config('services.serpapi.gl', 'us');
            $location = $validated['location'] ?? config('services.serpapi.location', 'United States');
            $offset = $validated['offset'] ?? 0;
            
            // Dispatch background job (service creates run record)
            ProcessAioRun::dispatch($hl, $gl, $location, $offset, $accountId);
            
            return response()->json([
                'success' => true,
                'message' => 'AIO run started in background',
            ]);
            
        } catch (\Exception $e) {
            Log::error('AIO run dispatch error: ' . $e->getMessage());
            
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Stop a running run
     */
    public function stop($id)
    {
        try {
            $updated = DB::table('runs')
                ->where('id', $id)
                ->where('status', 'running')
                ->update([
                    'status' => 'stopped',
                    'finished_at' => now(),
                ]);
            
            if ($updated === 0) {
                return response()->json(['error' => 'Run not found or already stopped'], 404);
            }
            
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Run stop error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}