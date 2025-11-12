<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RunController extends Controller
{
    private MonitoringService $monitoring;

    public function __construct(MonitoringService $monitoring)
    {
        $this->monitoring = $monitoring;
    }

    /**
     * Get all runs
     */
    public function index(Request $request)
    {
        $runs = DB::table('runs')
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
     * Start a new monitoring run (synchronous - runs immediately)
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

        // Increase limits for long-running process
        set_time_limit(600);
        ini_set('max_execution_time', '600');
        ignore_user_abort(true);

        $validated = $request->validate([
            'model' => 'nullable|string',
            'temp' => 'nullable|numeric',
            'offset' => 'nullable|integer',
        ]);

        try {
            $model = $validated['model'] ?? env('OPENAI_MODEL', 'gpt-4o-mini');
            $temp = $validated['temp'] ?? (float)env('TEMPERATURE', 0.2);
            $offset = $validated['offset'] ?? 0;

            // Run monitoring synchronously
            $result = $this->monitoring->runMonitoring($model, $temp, $offset);

            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('Monitoring run error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'error' => $e->getMessage(),
                'run_id' => null,
                'processed' => 0,
                'errors' => [['error' => $e->getMessage()]],
                'done' => false,
            ], 500);
        }
    }
    
    /**
     * Stop a running run (for future async implementation)
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

    /**
     * Start Google AIO monitoring run
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
        
        set_time_limit(600);
        ini_set('max_execution_time', '600');
        ignore_user_abort(true);

        $validated = $request->validate([
            'hl' => 'nullable|string|max:10',
            'gl' => 'nullable|string|max:10',
            'location' => 'nullable|string|max:100',
            'offset' => 'nullable|integer',
        ]);

        try {
            $aioService = app(\App\Services\AIOService::class);
            
            $hl = $validated['hl'] ?? env('SERPAPI_HL', 'en');
            $gl = $validated['gl'] ?? env('SERPAPI_GL', 'us');
            $location = $validated['location'] ?? env('SERPAPI_LOCATION', 'United States');
            $offset = $validated['offset'] ?? 0;

            $result = $aioService->runAIOMonitoring($hl, $gl, $location, $offset);

            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('AIO monitoring error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'error' => $e->getMessage(),
                'run_id' => null,
                'processed' => 0,
                'errors' => [['error' => $e->getMessage()]],
                'done' => false,
            ], 500);
        }
    }
}