<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Response;
use App\Models\Mention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MentionController extends Controller
{
    /**
     * Get current account ID
     */
    private function getAccountId(): int
    {
        $accountId = session('account_id');
        
        if (!$accountId) {
            throw new \Exception('No account selected');
        }
        
        return $accountId;
    }

    public function index(Request $request)
    {
        try {
            $accountId = $this->getAccountId();
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        $brand = $request->input('brand');
        $query = $request->input('q');
        $scope = $request->input('scope', 'latest_per_source');
        $model = $request->input('model', 'all');
        $sentiment = $request->input('sentiment');
        $intent = $request->input('intent');
        
        // Pagination params
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 50);
        $pageSize = min(max($pageSize, 1), 100);
        
        $queryBuilder = DB::table('mentions as m')
            ->join('responses as r', 'r.id', '=', 'm.response_id')
            ->join('runs', 'runs.id', '=', 'r.run_id')
            ->leftJoin('prompts as pr', 'pr.id', '=', 'r.prompt_id')
            ->where('r.account_id', $accountId); // ← ACCOUNT SCOPE
        
        // Apply scope filter
        if ($scope === 'latest') {
            $latestRunId = DB::table('runs')
                ->where('account_id', $accountId)
                ->max('id');
            $queryBuilder->where('r.run_id', $latestRunId);
        } elseif ($scope === 'latest_per_source') {
            $gptRunId = DB::table('runs')
                ->where('account_id', $accountId)
                ->where('model', 'like', 'gpt%')
                ->max('id');
            $aioRunId = DB::table('runs')
                ->where('account_id', $accountId)
                ->where('model', 'google-ai-overview')
                ->max('id');
            $runIds = array_filter([$gptRunId, $aioRunId]);
            if (!empty($runIds)) {
                $queryBuilder->whereIn('r.run_id', $runIds);
            }
        }
        
        // Brand filter
        if ($brand) {
            $queryBuilder->where('m.brand_id', $brand);
        }
        
        // Search query
        if ($query) {
            $queryBuilder->where(function($q) use ($query) {
                $q->where('pr.prompt', 'like', "%{$query}%")
                  ->orWhere('r.raw_answer', 'like', "%{$query}%");
            });
        }
        
        // Model/source filter
        if ($model === 'gpt') {
            $queryBuilder->where('runs.model', 'like', 'gpt%');
        } elseif ($model === 'google-ai-overview') {
            $queryBuilder->where('runs.model', 'google-ai-overview');
        }
        
        // Sentiment filter
        if ($sentiment) {
            $queryBuilder->where('m.sentiment', $sentiment);
        }
        
        // Intent filter
        if ($intent && $intent !== 'all') {
            $queryBuilder->where('r.intent', $intent);
        }
        
        // Get total count BEFORE pagination
        $total = $queryBuilder->count();
        
        // Apply pagination
        $mentions = $queryBuilder
            ->select(
                'm.response_id',
                'm.brand_id',
                'm.found_alias',
                'm.sentiment',
                DB::raw('COALESCE(r.prompt_category, pr.category) as category'),
                DB::raw('COALESCE(r.prompt_text, pr.prompt) as prompt'),
                'runs.run_at',
                'runs.model',
                'r.intent',
                DB::raw("CASE WHEN CHAR_LENGTH(r.raw_answer) > 260 THEN CONCAT(SUBSTRING(REPLACE(REPLACE(REPLACE(COALESCE(r.raw_answer,''), CHAR(10), ' '), CHAR(13), ' '), '  ', ' '), 1, 260), '…') ELSE REPLACE(REPLACE(REPLACE(COALESCE(r.raw_answer,''), CHAR(10), ' '), CHAR(13), ' '), '  ', ' ') END as snippet")
            )
            ->orderBy('r.id', 'desc')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();
        
        // Load links for these responses
        if ($mentions->isNotEmpty()) {
            $responseIds = $mentions->pluck('response_id')->unique()->toArray();
            
            $links = DB::table('response_links')
                ->where('account_id', $accountId) // ← ACCOUNT SCOPE
                ->whereIn('response_id', $responseIds)
                ->orderBy('id')
                ->get()
                ->groupBy('response_id');
            
            // Match best link per mention
            $rows = [];
            foreach ($mentions as $mention) {
                $rid = $mention->response_id;
                $alias = strtolower($mention->found_alias ?? '');
                $url = null;
                $anchor = null;
                $foundIn = null;
                
                if (isset($links[$rid])) {
                    foreach ($links[$rid] as $link) {
                        $a = strtolower($link->anchor ?? '');
                        if ($a && $alias && strpos($a, $alias) !== false) {
                            $anchor = $link->anchor;
                            $url = $link->url;
                            $foundIn = $link->found_in ?? null;
                            break;
                        }
                    }
                    if (!$url) {
                        foreach ($links[$rid] as $link) {
                            $u = strtolower($link->url ?? '');
                            if ($alias && strpos($u, $alias) !== false) {
                                $anchor = $link->anchor;
                                $url = $link->url;
                                $foundIn = $link->found_in ?? null;
                                break;
                            }
                        }
                    }
                }
                
                $rows[] = [
                    'response_id' => $mention->response_id,
                    'brand_id' => $mention->brand_id,
                    'found_alias' => $mention->found_alias,
                    'sentiment' => $mention->sentiment,
                    'category' => $mention->category,
                    'prompt' => $mention->prompt,
                    'run_at' => $mention->run_at,
                    'model' => $mention->model,
                    'intent' => $mention->intent,
                    'snippet' => $mention->snippet,
                    'anchor' => $anchor,
                    'url' => $url,
                    'found_in' => $foundIn,
                ];
            }
            
            return response()->json([
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
            ]);
        }
        
        return response()->json([
            'rows' => [],
            'total' => 0,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function show($responseId)
    {
        try {
            $accountId = $this->getAccountId();
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        // Verify response belongs to account
        $response = Response::where('id', $responseId)
            ->where('account_id', $accountId)
            ->firstOrFail();
        
        $links = $response->links;
        
        return response()->json([
            'response_id' => $response->id,
            'raw_answer' => $response->raw_answer,
            'links' => $links,
        ]);
    }

    /**
     * Export mentions to Google Sheets
     */
    public function exportToSheets(Request $request)
    {
        try {
            // ✅ NEW: Authenticate via database API key
            $apiKey = $request->get('api_key') ?: $request->header('X-API-Key');
            
            if (!$apiKey) {
                return response()->json(['error' => 'API key required'], 401);
            }
            
            // ✅ Find user by API key from database
            $user = \App\Models\User::where('api_key', $apiKey)->first();
            
            if (!$user) {
                return response()->json(['error' => 'Invalid API key'], 401);
            }
            
            // ✅ Log user in for this request
            auth()->login($user);
            
            // ✅ Set account context
            if ($user->accounts->isEmpty()) {
                return response()->json(['error' => 'No account associated with this user'], 403);
            }
            
            session(['account_id' => $user->accounts->first()->id]);
            
            $accountId = $this->getAccountId();
            
            // Get data using same logic as CSV export
            $mentions = $this->getMentionsForExport($request);
            
            // Return as JSON for Google Sheets App Script
            return response()->json([
                'success' => true,
                'data' => $mentions->map(function($m) {
                    return [
                        'Date' => $m->date,
                        'Source' => $m->source ?? 'Unknown',
                        'Brand' => $m->brand,
                        'Alias' => $m->alias,
                        'Sentiment' => $m->sentiment ?? 'N/A',
                        'Intent' => $m->intent ?? 'other',
                        'Category' => $m->prompt_category ?? 'uncategorized',
                        'Prompt' => $m->prompt_text,
                        'Answer' => $m->answer_snippet,
                        'Latency_ms' => $m->latency_ms,
                        'Tokens_In' => $m->tokens_in ?? 0,
                        'Tokens_Out' => $m->tokens_out ?? 0,
                    ];
                })->toArray(),
                'count' => $mentions->count(),
                'exported_at' => now()->toIso8601String(),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Sheets export error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper: Get mentions data for export
     */
    private function getMentionsForExport(Request $request)
    {
        $accountId = $this->getAccountId();
        
        $scope = $request->get('scope', 'latest_per_source');
        $brandFilter = $request->get('brand');
        $sentimentFilter = $request->get('sentiment');
        $intentFilter = $request->get('intent');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        
        $query = DB::table('mentions as m')
            ->join('responses as r', 'm.response_id', '=', 'r.id')
            ->join('brands as b', 'm.brand_id', '=', 'b.id')
            ->join('prompts as p', 'r.prompt_id', '=', 'p.id')
            ->leftJoin('runs', 'r.run_id', '=', 'runs.id')
            ->where('r.account_id', $accountId) // ← ACCOUNT SCOPE
            ->select(
                'r.created_at as date',
                'runs.model as source',
                'b.name as brand',
                'm.found_alias as alias',
                'm.sentiment',
                'r.intent',
                'p.category as prompt_category',
                'p.prompt as prompt_text',
                DB::raw('SUBSTRING(r.raw_answer, 1, 500) as answer_snippet'),
                'r.latency_ms',
                'r.tokens_in',
                'r.tokens_out'
            );
        
        if ($brandFilter) $query->where('b.id', $brandFilter);
        if ($sentimentFilter) $query->where('m.sentiment', $sentimentFilter);
        if ($intentFilter) $query->where('r.intent', $intentFilter);
        if ($startDate) $query->where('r.created_at', '>=', $startDate);
        if ($endDate) $query->where('r.created_at', '<=', $endDate . ' 23:59:59');
        
        if ($scope === 'latest_per_source') {
            $latestBySource = DB::table('runs')
                ->where('account_id', $accountId) // ← ACCOUNT SCOPE
                ->select('model', DB::raw('MAX(id) as max_id'))
                ->groupBy('model')
                ->pluck('max_id');
            $query->whereIn('r.run_id', $latestBySource);
        }
        
        return $query->orderBy('r.created_at', 'desc')->limit(10000)->get();
    }

    /**
     * Export mentions to CSV
     */
    public function export(Request $request)
    {
        try {
            $mentions = $this->getMentionsForExport($request);
            $filename = 'mentions_export_' . now()->format('Y-m-d_His') . '.csv';
            
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
            ];
            
            $callback = function() use ($mentions) {
                $file = fopen('php://output', 'w');
                fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
                
                fputcsv($file, [
                    'Date', 'Source', 'Brand', 'Alias Found', 'Sentiment', 
                    'Intent', 'Prompt Category', 'Prompt Text', 'Answer Snippet',
                    'Latency (ms)', 'Tokens In', 'Tokens Out'
                ]);
                
                foreach ($mentions as $m) {
                    fputcsv($file, [
                        $m->date, $m->source ?? 'Unknown', $m->brand, $m->alias,
                        $m->sentiment ?? 'N/A', $m->intent ?? 'other',
                        $m->prompt_category ?? 'uncategorized', $m->prompt_text,
                        $m->answer_snippet, $m->latency_ms,
                        $m->tokens_in ?? 0, $m->tokens_out ?? 0,
                    ]);
                }
                
                fclose($file);
            };
            
            return response()->stream($callback, 200, $headers);
            
        } catch (\Exception $e) {
            Log::error('CSV export error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function exportForWindsor(Request $request)
    {
        try {
            // ✅ NEW: Authenticate via database API key
            $apiKey = $request->get('api_key') ?: $request->header('X-API-Key');
            
            if (!$apiKey) {
                return response()->json(['error' => 'API key required'], 401);
            }
            
            // ✅ Find user by API key from database
            $user = \App\Models\User::where('api_key', $apiKey)->first();
            
            if (!$user) {
                return response()->json(['error' => 'Invalid API key'], 401);
            }
            
            // ✅ Log user in for this request
            auth()->login($user);
            
            // ✅ Set account context
            if ($user->accounts->isEmpty()) {
                return response()->json(['error' => 'No account associated with this user'], 403);
            }
            
            session(['account_id' => $user->accounts->first()->id]);
            
            $accountId = $this->getAccountId();
            $mentions = $this->getMentionsForExport($request);
            
            $data = $mentions->map(function($m, $index) {
                return [
                    // ========== UNIQUE IDENTIFIER ==========
                    'record_id' => 'mention_' . ($m->mention_id ?? $index), 
                    
                    // ========== RECORD TYPE ==========
                    'record_type' => 'mention',
                    
                    // ========== EXISTING CORE FIELDS ==========
                    'date' => $m->date,
                    'source' => $m->source ?? 'Unknown',
                    'brand' => $m->brand,
                    'alias' => $m->alias,
                    'sentiment' => $m->sentiment ?? 'neutral',
                    'intent' => $m->intent ?? 'other',
                    'category' => $m->prompt_category ?? 'uncategorized',
                    'prompt' => substr($m->prompt_text, 0, 200),
                    'answer' => substr($m->answer_snippet, 0, 200),
                    'latency_ms' => (int)$m->latency_ms,
                    'tokens_in' => (int)($m->tokens_in ?? 0),
                    'tokens_out' => (int)($m->tokens_out ?? 0),
                    
                    // ========== BRANDED VS NON-BRANDED ==========
                    'query_brand_type' => $this->getQueryBrandType($m->prompt_text, $m->brand),
                    'query_brand_count' => $this->countBrandsInQuery($m->prompt_text),
                    'is_own_brand_query' => $this->queryContainsBrand($m->prompt_text, $m->brand) ? 1 : 0,
                    'is_competitor_query' => $this->queryContainsCompetitor($m->prompt_text, $m->brand) ? 1 : 0,
                    'is_pure_non_branded' => $this->isPureNonBranded($m->prompt_text) ? 1 : 0,
                    'competitor_in_query' => $this->getCompetitorInQuery($m->prompt_text),
                    
                    // ========== VISIBILITY METRICS ==========
                    'mention_position' => $this->getMentionPosition($m->answer_snippet, $m->alias),
                    'is_first_mention' => $this->isFirstBrand($m->answer_snippet, $m->alias) ? 1 : 0,
                    'is_only_mention' => $this->isOnlyBrand($m->answer_snippet, $m->brand) ? 1 : 0,
                    'mention_count_in_answer' => substr_count(strtolower($m->answer_snippet), strtolower($m->alias)),
                    'competitors_mentioned' => $this->getCompetitorCount($m->answer_snippet),
                    'competitor_list' => $this->getCompetitorList($m->answer_snippet),
                    
                    // ========== COMPETITIVE INTELLIGENCE ==========
                    'vs_draftkings' => $this->isMentionedWith($m->answer_snippet, 'draftkings') ? 1 : 0,
                    'vs_fanduel' => $this->isMentionedWith($m->answer_snippet, 'fanduel') ? 1 : 0,
                    'vs_betonline' => $this->isMentionedWith($m->answer_snippet, 'betonline') ? 1 : 0,
                    
                    // ========== CONTENT QUALITY ==========
                    'has_call_to_action' => $this->hasCallToAction($m->answer_snippet) ? 1 : 0,
                    'has_direct_link' => $this->hasBrandLink($m->answer_snippet, $m->brand) ? 1 : 0,
                    'mention_type' => $this->getMentionType($m->answer_snippet, $m->alias),
                    'answer_length' => strlen($m->answer_snippet),
                    
                    // ========== QUERY INTELLIGENCE ==========
                    'query_type' => $this->classifyQueryType($m->prompt_text),
                    'is_best_query' => str_contains(strtolower($m->prompt_text), 'best') ? 1 : 0,
                    'is_comparison_query' => $this->isComparisonQuery($m->prompt_text) ? 1 : 0,
                    'sport_mentioned' => (string)$this->extractSport($m->prompt_text),
                    'feature_mentioned' => $this->extractFeature($m->prompt_text),
                    
                    // ========== TIME ANALYSIS ==========
                    'month' => \Carbon\Carbon::parse($m->date)->format('Y-m'),
                    'week_of_year' => \Carbon\Carbon::parse($m->date)->weekOfYear,
                    'day_of_week' => \Carbon\Carbon::parse($m->date)->format('l'),
                    'quarter' => 'Q' . \Carbon\Carbon::parse($m->date)->quarter,
                    
                    // ========== BINARY FLAGS FOR EASY FILTERING ==========
                    'is_positive' => ($m->sentiment === 'positive') ? 1 : 0,
                    'is_negative' => ($m->sentiment === 'negative') ? 1 : 0,
                    'is_neutral' => ($m->sentiment === 'neutral') ? 1 : 0,
                    
                    // ========== COMPOSITE SCORE ==========
                    'visibility_score' => $this->calculateVisibilityScore($m),
                    
                    // ========== MISSED OPP FIELDS (null for mention records) ==========
                    'search_volume' => null,
                    'opportunity_score' => null,
                ];
            })->values()->toArray();
            
            // ========== ADD MISSED OPPORTUNITIES ==========
            $yourBrand = DB::table('brands')
                ->where('account_id', $accountId) // ← ACCOUNT SCOPE
                ->where('name', 'BetUS')
                ->first();
            
            $brandId = $yourBrand ? $yourBrand->id : null;
            
            if ($brandId) {
                $startDate = $request->get('start_date');
                $endDate = $request->get('end_date');
                $source = $request->get('source');
                
                $filterSQL = [];
                $filterParams = [];
                
                if ($startDate) {
                    $filterSQL[] = "r.created_at >= ?";
                    $filterParams[] = $startDate;
                }
                
                if ($endDate) {
                    $filterSQL[] = "r.created_at <= ?";
                    $filterParams[] = $endDate . ' 23:59:59';
                }
                
                if ($source) {
                    $filterSQL[] = "runs.model = ?";
                    $filterParams[] = $source;
                }
                
                $filterSQLString = !empty($filterSQL) ? implode(' AND ', $filterSQL) : '';
                
                // Call MetricsController method
                $metricsController = app(\App\Http\Controllers\Api\MetricsController::class);
                $missedOpps = $metricsController->getMissedOpportunities($brandId, $filterSQLString, $filterParams);
                
                // Transform missed opps to match data structure
                $missedOppsData = array_map(function($opp, $index) use ($yourBrand) {
                    return [
                        'record_id' => 'missed_' . ($opp['prompt_id'] ?? $index),
                        'record_type' => 'missed_opportunity',
                        'date' => now()->format('Y-m-d H:i:s'),
                        'source' => 'All',
                        'brand' => $yourBrand->name,
                        'alias' => strtolower($yourBrand->name),
                        'sentiment' => null,
                        'intent' => $opp['intent'] ?? 'other',
                        'category' => $opp['category'],
                        'prompt' => $opp['prompt'],
                        'answer' => null,
                        'latency_ms' => null,
                        'tokens_in' => null,
                        'tokens_out' => null,
                        'query_brand_type' => $this->getQueryBrandType($opp['prompt'], 'BetUS'),
                        'query_brand_count' => $this->countBrandsInQuery($opp['prompt']),
                        'is_own_brand_query' => $this->queryContainsBrand($opp['prompt'], 'BetUS') ? 1 : 0,
                        'is_competitor_query' => $this->queryContainsCompetitor($opp['prompt'], 'BetUS') ? 1 : 0,
                        'is_pure_non_branded' => $this->isPureNonBranded($opp['prompt']) ? 1 : 0,
                        'competitor_in_query' => $this->getCompetitorInQuery($opp['prompt']),
                        'mention_position' => null,
                        'is_first_mention' => 0,
                        'is_only_mention' => 0,
                        'mention_count_in_answer' => 0,
                        'competitors_mentioned' => $opp['competitor_share'] ?? 0,
                        'competitor_list' => 'Unknown',
                        'vs_draftkings' => 0,
                        'vs_fanduel' => 0,
                        'vs_betonline' => 0,
                        'has_call_to_action' => 0,
                        'has_direct_link' => 0,
                        'mention_type' => null,
                        'answer_length' => null,
                        'query_type' => $this->classifyQueryType($opp['prompt']),
                        'is_best_query' => str_contains(strtolower($opp['prompt']), 'best') ? 1 : 0,
                        'is_comparison_query' => $this->isComparisonQuery($opp['prompt']) ? 1 : 0,
                        'sport_mentioned' => $this->extractSport($opp['prompt']),
                        'feature_mentioned' => $this->extractFeature($opp['prompt']),
                        'month' => now()->format('Y-m'),
                        'week_of_year' => now()->weekOfYear,
                        'day_of_week' => now()->format('l'),
                        'quarter' => 'Q' . now()->quarter,
                        'is_positive' => 0,
                        'is_negative' => 0,
                        'is_neutral' => 0,
                        'visibility_score' => 0,
                        'search_volume' => $opp['search_volume'] ?? 0,
                        'opportunity_score' => $opp['priority'] ?? 50,
                    ];
                }, $missedOpps, array_keys($missedOpps));
                
                $data = array_merge($data, $missedOppsData);
            }
            
            return response()->json($data)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', '*')
                ->header('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            Log::error('Windsor export error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'error' => $e->getMessage()
            ], 500)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Content-Type', 'application/json');
        }
    }

    // ========================================
    // ANALYSIS HELPER METHODS (keep all existing ones)
    // ========================================
    
    private function getAllBrands(): array
    {
        return [
            'betus', 'draftkings', 'fanduel', 'betonline', 'bovada',
            'caesars', 'mgm', 'betmgm', 'pointsbet', 'betrivers',
            'wynnbet', 'unibet', 'fox bet', 'barstool'
        ];
    }

    private function getQueryBrandType(string $prompt, string $mentionedBrand): string
    {
        $prompt = strtolower($prompt);
        $allBrands = $this->getAllBrands();
        
        $brandsInQuery = [];
        foreach ($allBrands as $brand) {
            if (str_contains($prompt, $brand)) {
                $brandsInQuery[] = $brand;
            }
        }
        
        if (empty($brandsInQuery)) {
            return 'non_branded';
        }
        
        $ownBrand = strtolower($mentionedBrand);
        
        if (count($brandsInQuery) === 1) {
            if ($brandsInQuery[0] === $ownBrand) {
                return 'own_brand_only';
            } else {
                return 'competitor_only';
            }
        }
        
        if (count($brandsInQuery) > 1) {
            if (in_array($ownBrand, $brandsInQuery)) {
                return 'own_brand_comparison';
            } else {
                return 'competitor_comparison';
            }
        }
        
        return 'unknown';
    }

    private function countBrandsInQuery(string $prompt): int
    {
        $allBrands = $this->getAllBrands();
        $count = 0;
        
        foreach ($allBrands as $brand) {
            if (str_contains(strtolower($prompt), $brand)) {
                $count++;
            }
        }
        
        return $count;
    }

    private function queryContainsBrand(string $prompt, string $brand): bool
    {
        return str_contains(strtolower($prompt), strtolower($brand));
    }

    private function queryContainsCompetitor(string $prompt, string $ownBrand): bool
    {
        $allBrands = $this->getAllBrands();
        $ownBrandLower = strtolower($ownBrand);
        
        foreach ($allBrands as $brand) {
            if ($brand !== $ownBrandLower && str_contains(strtolower($prompt), $brand)) {
                return true;
            }
        }
        
        return false;
    }

    private function isPureNonBranded(string $prompt): bool
    {
        $allBrands = $this->getAllBrands();
        
        foreach ($allBrands as $brand) {
            if (str_contains(strtolower($prompt), $brand)) {
                return false;
            }
        }
        
        return true;
    }

    private function getCompetitorInQuery(string $prompt): ?string
    {
        $competitors = ['draftkings', 'fanduel', 'betonline', 'bovada', 
                        'caesars', 'mgm', 'betmgm', 'pointsbet', 'betrivers'];
        
        foreach ($competitors as $comp) {
            if (str_contains(strtolower($prompt), $comp)) {
                return ucfirst($comp);
            }
        }
        
        return null;
    }

    private function getMentionPosition(string $text, string $alias): int
    {
        $brands = $this->getAllBrands();
        $positions = [];
        
        foreach ($brands as $brand) {
            $pos = stripos($text, $brand);
            if ($pos !== false) {
                $positions[$brand] = $pos;
            }
        }
        
        asort($positions);
        $ranking = array_keys($positions);
        
        $key = array_search(strtolower($alias), $ranking);
        
        return $key !== false ? $key + 1 : 0;
    }

    private function isFirstBrand(string $text, string $alias): bool
    {
        return $this->getMentionPosition($text, $alias) === 1;
    }

    private function isOnlyBrand(string $text, string $brand): bool
    {
        $allBrands = $this->getAllBrands();
        $count = 0;
        
        foreach ($allBrands as $b) {
            if (stripos($text, $b) !== false) {
                $count++;
            }
        }
        
        return $count === 1;
    }

    private function getCompetitorCount(string $text): int
    {
        $competitors = ['draftkings', 'fanduel', 'betonline', 'bovada', 
                        'caesars', 'mgm', 'betmgm', 'pointsbet', 'betrivers'];
        $count = 0;
        
        foreach ($competitors as $comp) {
            if (stripos($text, $comp) !== false) {
                $count++;
            }
        }
        
        return $count;
    }

    private function getCompetitorList(string $text): string
    {
        $competitors = ['DraftKings', 'FanDuel', 'BetOnline', 'Bovada', 
                        'Caesars', 'MGM', 'BetMGM', 'PointsBet', 'BetRivers'];
        $found = [];
        
        foreach ($competitors as $comp) {
            if (stripos($text, $comp) !== false) {
                $found[] = $comp;
            }
        }
        
        return implode(', ', $found) ?: 'None';
    }

    private function isMentionedWith(string $text, string $competitor): bool
    {
        return stripos($text, $competitor) !== false;
    }

    private function hasCallToAction(string $text): bool
    {
        $ctas = ['visit', 'sign up', 'try', 'check out', 'register', 
                'join', 'start', 'get started', 'click here', 'learn more'];
        
        $text = strtolower($text);
        
        foreach ($ctas as $cta) {
            if (str_contains($text, $cta)) {
                return true;
            }
        }
        
        return false;
    }

    private function hasBrandLink(string $text, string $brand): bool
    {
        $brandDomain = strtolower(str_replace([' ', '.'], '', $brand));
        $text = strtolower($text);
        
        return str_contains($text, $brandDomain . '.com') || 
               str_contains($text, $brandDomain . '.pa') ||
               str_contains($text, $brandDomain . '.ag');
    }

    private function getMentionType(string $text, string $alias): string
    {
        $text = strtolower($text);
        
        $recommendations = ['recommend', 'best', 'top choice', 'great option', 'try', 'consider'];
        foreach ($recommendations as $rec) {
            if (str_contains($text, $rec)) {
                return 'recommendation';
            }
        }
        
        $comparisons = ['vs', 'versus', 'compared to', 'better than'];
        foreach ($comparisons as $comp) {
            if (str_contains($text, $comp)) {
                return 'comparison';
            }
        }
        
        $warnings = ['warning', 'caution', 'avoid', 'careful', 'risk'];
        foreach ($warnings as $warn) {
            if (str_contains($text, $warn)) {
                return 'warning';
            }
        }
        
        return 'factual';
    }

    private function classifyQueryType(string $prompt): string
    {
        $prompt = strtolower($prompt);
        
        if (str_contains($prompt, 'best')) return 'best';
        
        if (str_contains($prompt, 'vs') || str_contains($prompt, 'versus') || str_contains($prompt, 'compare')) {
            return 'comparison';
        }
        
        if (str_contains($prompt, 'review') || str_contains($prompt, 'opinion')) {
            return 'review';
        }
        
        if (str_contains($prompt, 'how to') || str_contains($prompt, 'guide') || str_contains($prompt, 'tutorial')) {
            return 'how-to';
        }
        
        if (str_contains($prompt, 'bonus') || str_contains($prompt, 'promo') || 
            str_contains($prompt, 'offer') || str_contains($prompt, 'free')) {
            return 'promotion';
        }
        
        if (str_contains($prompt, 'safe') || str_contains($prompt, 'legal') || 
            str_contains($prompt, 'legit') || str_contains($prompt, 'trust')) {
            return 'trust';
        }
        
        if (str_contains($prompt, 'sign up') || str_contains($prompt, 'register') || 
            str_contains($prompt, 'create account')) {
            return 'signup';
        }
        
        return 'general';
    }

    private function isComparisonQuery(string $prompt): bool
    {
        $prompt = strtolower($prompt);
        
        return str_contains($prompt, 'vs') || 
               str_contains($prompt, 'versus') || 
               str_contains($prompt, 'compare') || 
               str_contains($prompt, 'comparison') || 
               str_contains($prompt, ' or ') || 
               str_contains($prompt, 'better');
    }

    private function extractSport(string $prompt): ?string
    {
        $prompt = strtolower($prompt);
        
        $sports = [
            'NFL' => ['nfl', 'football', 'super bowl', 'gridiron'],
            'NBA' => ['nba', 'basketball', 'hoops'],
            'MLB' => ['mlb', 'baseball'],
            'NHL' => ['nhl', 'hockey', 'ice hockey'],
            'Soccer' => ['soccer', 'mls', 'premier league', 'world cup', 'fifa'],
            'UFC/MMA' => ['ufc', 'mma', 'mixed martial'],
            'Boxing' => ['boxing', 'fight'],
            'Tennis' => ['tennis', 'wimbledon', 'us open'],
            'Golf' => ['golf', 'pga', 'masters'],
            'NASCAR' => ['nascar', 'racing', 'daytona'],
            'College Football' => ['college football', 'ncaa football', 'cfb'],
            'College Basketball' => ['college basketball', 'march madness', 'ncaa basketball'],
        ];
        
        foreach ($sports as $sportName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($prompt, $keyword)) {
                    return $sportName;
                }
            }
        }
        
        return 'Others';
    }

    private function extractFeature(string $prompt): ?string
    {
        $features = [
            'odds' => ['odds', 'lines', 'spread'],
            'bonus' => ['bonus', 'promo', 'promotion', 'offer'],
            'live' => ['live', 'in-play', 'real-time'],
            'app' => ['app', 'mobile', 'download'],
            'withdrawal' => ['withdraw', 'payout', 'cashout'],
            'parlay' => ['parlay', 'teaser'],
        ];
        
        $prompt = strtolower($prompt);
        
        foreach ($features as $feature => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($prompt, $keyword)) {
                    return ucfirst($feature);
                }
            }
        }
        
        return 'Others';
    }

    private function calculateVisibilityScore($m): int
    {
        $score = 0;
        
        $score += 10;
        
        if ($this->isPureNonBranded($m->prompt_text)) {
            $score += 25;
        } elseif ($this->queryContainsBrand($m->prompt_text, $m->brand)) {
            $score += 5;
        }
        if ($this->isFirstBrand($m->answer_snippet, $m->alias)) {
            $score += 20;
        } elseif ($this->getMentionPosition($m->answer_snippet, $m->alias) === 2) {
            $score += 10;
        }
        
        if ($this->isOnlyBrand($m->answer_snippet, $m->brand)) {
            $score += 30;
        }
        
        if ($m->sentiment === 'positive') {
            $score += 15;
        } elseif ($m->sentiment === 'negative') {
            $score -= 20;
        }
        
        if ($this->hasCallToAction($m->answer_snippet)) {
            $score += 10;
        }
        
        if ($this->hasBrandLink($m->answer_snippet, $m->brand)) {
            $score += 10;
        }
        
        if ($m->intent === 'transactional') {
            $score += 15;
        } elseif ($m->intent === 'informational') {
            $score += 5;
        }
        
        if ($this->getMentionType($m->answer_snippet, $m->alias) === 'recommendation') {
            $score += 20;
        } elseif ($this->getMentionType($m->answer_snippet, $m->alias) === 'warning') {
            $score -= 30;
        }
        
        return max(0, $score);
    }
}