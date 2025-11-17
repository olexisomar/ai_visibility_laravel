<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Models\Persona;
use App\Services\LLMService;
use App\Services\SerpAPIService;
use App\Services\GSCService;
use App\Services\BrandTokenService;
use App\Services\QueryNormalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TopicController extends Controller
{
    private LLMService $llm;
    private SerpAPIService $serpapi;
    private GSCService $gsc;
    private BrandTokenService $brandTokens;
    private QueryNormalizationService $normalizer;

    public function __construct(
        LLMService $llm,
        SerpAPIService $serpapi,
        GSCService $gsc,
        BrandTokenService $brandTokens,
        QueryNormalizationService $normalizer
    ) {
        $this->llm = $llm;
        $this->serpapi = $serpapi;
        $this->gsc = $gsc;
        $this->brandTokens = $brandTokens;
        $this->normalizer = $normalizer;
    }

    // ========== EXISTING METHODS (UNCHANGED) ==========

    public function index(Request $request)
    {
        $topics = DB::table('topics')
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json(['rows' => $topics]);
    }
    
    public function store(Request $request)
    {
        set_time_limit(600);
        
        $topic = $request->input('topic');
        $personaStart = max(0, (int)$request->input('persona_start', 0));
        $personaLimit = max(1, min((int)$request->input('persona_limit', 4), 10));
        
        if (!$topic) {
            return response()->json(['error' => 'Topic required'], 400);
        }

        // Set deadline for this request
        $budget = (int)config('services.topics.time_budget_sec');
        if (!defined('SAVE_TOPICS_DEADLINE')) {
            define('SAVE_TOPICS_DEADLINE', microtime(true) + $budget);
        }

        try {
            // Get all active personas
            $personas = DB::table('personas')
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->get()
                ->toArray();

            if (empty($personas)) {
                return response()->json([
                    'error' => 'No personas found. Create at least one persona in Config → Personas.'
                ], 400);
            }

            $totalPersonas = count($personas);

            // Upsert topic
            DB::table('topics')->updateOrInsert(
                ['name' => $topic],
                ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
            );

            $topicRow = DB::table('topics')->where('name', $topic)->first();
            if (!$topicRow) {
                return response()->json(['error' => 'Failed to create topic'], 500);
            }

            // Check cooldown (10 minutes)
            $shouldGenerate = true;
            if ($topicRow->last_generated_at) {
                $minutes = DB::selectOne(
                    "SELECT TIMESTAMPDIFF(MINUTE, ?, NOW()) as mins",
                    [$topicRow->last_generated_at]
                )->mins;
                
                $shouldGenerate = $minutes >= 10;
            }

            if (!$shouldGenerate) {
                return response()->json([
                    'ok' => true,
                    'topic' => $topic,
                    'processed_personas' => 0,
                    'generated' => 0,
                    'next_persona' => $personaStart,
                    'done' => false,
                    'message' => 'Topic generation on cooldown (10 min minimum)',
                ]);
            }

            // Process batch of personas
            $result = $this->processTopicPersonas(
                $topic,
                $topicRow->id,
                $personas,
                $personaStart,
                $personaLimit
            );

            $nextPersona = $personaStart + $result['processed'];
            $done = $nextPersona >= $totalPersonas;

            // If we processed all personas, update last_generated_at
            if ($done) {
                DB::table('topics')
                    ->where('id', $topicRow->id)
                    ->update(['last_generated_at' => now()]);
            }

            return response()->json([
                'ok' => true,
                'topic' => $topic,
                'processed_personas' => $result['processed'],
                'generated' => $result['generated'],
                'next_persona' => $nextPersona,
                'done' => $done,
            ]);

        } catch (\Exception $e) {
            Log::error('Topic generation error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function setActive(Request $request)
    {
        $id = $request->input('id');
        $active = $request->input('active', 1);
        
        DB::table('topics')
            ->where('id', $id)
            ->update(['is_active' => $active, 'updated_at' => now()]);
        
        return response()->json(['ok' => true]);
    }
    
    public function touch(Request $request)
    {
        $id = $request->input('id');
        
        DB::table('topics')
            ->where('id', $id)
            ->update(['last_generated_at' => null, 'updated_at' => now()]);
        
        return response()->json(['ok' => true]);
    }

    // ========== NEW METHODS (ADDED FOR PERSONA MAPPING) ==========

    /**
     * Get topics with persona mappings for new UI
     */
    public function indexWithPersonas(Request $request)
    {
        try {
            $topics = Topic::with(['personas'])
                ->where('is_deleted', false)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($topic) {
                    return [
                        'id' => $topic->id,
                        'name' => $topic->name,
                        'is_active' => $topic->is_active,
                        'last_generated_at' => $topic->last_generated_at?->format('Y-m-d H:i:s'),
                        'personas' => $topic->personas->map(fn($p) => [
                            'id' => $p->id,
                            'name' => $p->name,
                        ]),
                        'persona_count' => $topic->personas->count(),
                        'pending_suggestions' => $topic->pendingSuggestionsCount(),
                        'approved_prompts' => $topic->approvedPromptsCount(),
                    ];
                });

            return response()->json(['rows' => $topics]);
        } catch (\Exception $e) {
            Log::error('Topic index with personas error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store topic with persona mappings
     */
    public function storeWithPersonas(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'persona_ids' => 'required|array|min:1',
            'persona_ids.*' => 'exists:personas,id',
            'brand_id' => 'nullable|string|max:100'
        ], [
            'persona_ids.required' => 'Please select at least one persona for this topic.',
            'persona_ids.min' => 'Please select at least one persona for this topic.',
        ]);

        try {
            DB::beginTransaction();

            // Create or update topic
            $topic = Topic::updateOrCreate(
                ['name' => $request->name],
                [
                    'brand_id' => $request->brand_id,
                    'is_active' => true,
                    'is_deleted' => false
                ]
            );

            // Sync personas
            $topic->personas()->sync($request->persona_ids);

            DB::commit();

            return response()->json([
                'ok' => true,
                'message' => 'Topic saved successfully',
                'topic' => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'persona_count' => count($request->persona_ids)
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Store topic with personas error: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update persona mappings for a topic
     */
    public function updatePersonas(Request $request, $id)
    {
        $request->validate([
            'persona_ids' => 'required|array|min:1',
            'persona_ids.*' => 'exists:personas,id',
        ]);

        try {
            $topic = Topic::findOrFail($id);
            $topic->personas()->sync($request->persona_ids);

            return response()->json([
                'ok' => true,
                'message' => 'Personas updated successfully',
                'persona_count' => count($request->persona_ids)
            ]);
        } catch (\Exception $e) {
            Log::error('Update personas error: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active personas for dropdown
     */
    public function getActivePersonas()
    {
        try {
            $personas = Persona::where('is_active', true)
                ->where('is_deleted', false)
                ->orderBy('name')
                ->get(['id', 'name']);

            return response()->json($personas);
        } catch (\Exception $e) {
            Log::error('Get personas error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Soft delete topic
     */
    public function destroy($id)
    {
        try {
            DB::table('topics')
                ->where('id', $id)
                ->update([
                    'is_deleted' => 1,
                    'is_active' => 0,
                    'updated_at' => now()
                ]);

            return response()->json([
                'ok' => true,
                'message' => 'Topic deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Delete topic error: ' . $e->getMessage());
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ========== EXISTING PRIVATE METHODS (UNCHANGED) ==========

    /**
     * Process a batch of personas for a topic
     */
    private function processTopicPersonas(
        string $topic,
        int $topicId,
        array $personas,
        int $startIdx,
        int $limit
    ): array {
        $hl = config('services.serpapi.hl');
        $gl = config('services.serpapi.gl');
        
        // Get GSC weights for scoring
        $primaryProperty = $this->gsc->getPrimaryProperty();
        $weights = $this->gsc->getWeights($primaryProperty);

        // Get LLM providers
        $providers = $this->llm->getProviders();

        // Env configs
        $maxAITotal = (int)config('services.topics.max_ai_total');
        $maxAIPerProvider = (int)config('services.topics.max_ai_per_provider');
        $paaPerSeed = (int)config('services.topics.paa_per_seed');
        $minBrandedQueries = (int)config('services.topics.min_branded_queries');

        $processed = 0;
        $generated = 0;

        $endIdx = min(count($personas), $startIdx + $limit);

        for ($i = $startIdx; $i < $endIdx; $i++) {
            if (microtime(true) > SAVE_TOPICS_DEADLINE - 2) {
                Log::info("[Topics] Budget low, stopping at persona index $i");
                break;
            }

            $persona = (array)$personas[$i];
            $processed++;

            // Get brand tokens
            $brandId = !empty($persona['brand_id']) ? (string)$persona['brand_id'] : null;
            $tokens = $this->brandTokens->getBrandTokens($brandId);

            // Generate queries from all providers
            $allResults = [];
            foreach ($providers as $provider) {
                try {
                    $result = $this->llm->generateQueries(
                        $provider,
                        $topic,
                        $persona,
                        $tokens,
                        $hl,
                        $gl
                    );

                    $allResults[] = [
                        'provider' => $provider,
                        'generic' => $result['generic'],
                        'branded' => $result['branded'],
                    ];
                } catch (\Exception $e) {
                    Log::warning("Provider {$provider['provider']} failed: " . $e->getMessage());
                    continue;
                }
            }

            if (empty($allResults)) {
                continue;
            }

            // Rank and select top queries
            $seeds = $this->rankAndSelectQueries(
                $allResults,
                $tokens,
                $weights,
                $maxAITotal,
                $maxAIPerProvider,
                $minBrandedQueries
            );

            if (empty($seeds)) {
                continue;
            }

            // Insert seeds into raw_suggestions WITH topic_id
            $rank = 1;
            foreach ($seeds as $seed) {
                $inserted = $this->insertSuggestion(
                    $seed['q'],
                    $topic,
                    $topicId,  // Pass topicId now
                    $persona['id'],
                    $seed['prov'],
                    $seed['b'],
                    $hl,
                    $gl,
                    $rank,
                    $seed['w']
                );

                if ($inserted) {
                    $generated++;
                }
                $rank++;
            }

            // PAA enrichment
            if ($paaPerSeed > 0 && microtime(true) < SAVE_TOPICS_DEADLINE) {
                $paaGenerated = $this->enrichWithPAA(
                    $seeds,
                    $tokens,
                    $topic,
                    $topicId,  // Pass topicId now
                    $persona['id'],
                    $hl,
                    $gl,
                    $paaPerSeed
                );
                $generated += $paaGenerated;
            }
        }

        return [
            'processed' => $processed,
            'generated' => $generated,
        ];
    }

    /**
     * Rank queries and select best ones per provider
     */
    private function rankAndSelectQueries(
        array $allResults,
        array $tokens,
        array $weights,
        int $maxAITotal,
        int $maxAIPerProvider,
        int $minBrandedQueries
    ): array {
        $hasBrand = !empty($tokens['brand']);
        
        // Rank queries by weight within each provider
        $perProviderSeeds = [];
        foreach ($allResults as $bundle) {
            $ranked = [];
            
            foreach ($bundle['generic'] as $q) {
                $ranked[] = [
                    'q' => $q,
                    'b' => 0,
                    'w' => $weights ? $this->gsc->scoreQuery($q, $weights) : 0,
                    'prov' => $bundle['provider'],
                ];
            }
            
            foreach ($bundle['branded'] as $q) {
                $ranked[] = [
                    'q' => $q,
                    'b' => 1,
                    'w' => ($weights ? $this->gsc->scoreQuery($q, $weights) : 0) + 150,
                    'prov' => $bundle['provider'],
                ];
            }

            usort($ranked, fn($a, $b) => $b['w'] <=> $a['w']);

            if ($hasBrand) {
                $branded = array_filter($ranked, fn($r) => $r['b'] === 1);
                $generic = array_filter($ranked, fn($r) => $r['b'] === 0);
                
                $takeBranded = array_slice($branded, 0, max($minBrandedQueries, (int)floor($maxAIPerProvider * 0.4)));
                $takeGeneric = array_slice($generic, 0, $maxAIPerProvider);
                
                $ranked = array_merge($takeBranded, $takeGeneric);
                usort($ranked, fn($a, $b) => $b['w'] <=> $a['w']);
                $ranked = array_slice($ranked, 0, $maxAIPerProvider);
            } else {
                $ranked = array_slice($ranked, 0, $maxAIPerProvider);
            }

            $perProviderSeeds[] = $ranked;
        }

        $providerCount = count($perProviderSeeds);
        if ($providerCount === 0) {
            return [];
        }

        $baseQuota = (int)floor($maxAITotal / $providerCount);
        $remainder = $maxAITotal - ($baseQuota * $providerCount);

        $queues = [];
        foreach ($perProviderSeeds as $list) {
            if (empty($list)) continue;
            
            $provName = strtolower($list[0]['prov']['provider']);
            if (!isset($queues[$provName])) {
                $queues[$provName] = [];
            }
            foreach ($list as $item) {
                $queues[$provName][] = $item;
            }
        }

        $preSeeds = [];
        if ($hasBrand) {
            foreach (array_keys($queues) as $provName) {
                foreach ($queues[$provName] as $idx => $item) {
                    if (!empty($item['b'])) {
                        $preSeeds[] = $item;
                        array_splice($queues[$provName], $idx, 1);
                        break;
                    }
                }
            }
        }

        $selected = [];
        $normSeen = [];
        
        foreach (array_keys($queues) as $i => $provName) {
            $quota = $baseQuota + ($i < $remainder ? 1 : 0);
            $picked = 0;
            
            while ($picked < $quota && !empty($queues[$provName])) {
                $item = array_shift($queues[$provName]);
                $normalized = $this->normalizer->normalize($item['q']);
                
                if (!isset($normSeen[$normalized])) {
                    $normSeen[$normalized] = true;
                    $selected[] = $item;
                    $picked++;
                }
            }
        }

        $seeds = array_merge($preSeeds, $selected);
        usort($seeds, fn($a, $b) => $b['w'] <=> $a['w']);

        return $seeds;
    }

    /**
     * Insert suggestion into raw_suggestions table
     * UPDATED: Now includes topic_id
     */
    private function insertSuggestion(
        string $text,
        string $topic,
        int $topicId,      // ADDED
        int $personaId,
        array $provider,
        int $isBranded,
        string $hl,
        string $gl,
        int $rank,
        int $weight
    ): bool {
        [$normalized, $hash] = $this->normalizer->normalizeAndHash($text);

        $providerName = strtolower($provider['provider']);
        $sourceTag = ($providerName === 'openai') ? 'ai-gpt' : (($providerName === 'gemini') ? 'ai-gemini' : 'ai-unknown');
        $source = $sourceTag . ($isBranded ? '-branded' : '');

        $score = max(30, min(99, 60 + (int)log(1 + max(0, $weight))));
        $confidence = 80;

        try {
            return DB::table('raw_suggestions')->insertOrIgnore([
                'text' => $text,
                'normalized' => $normalized,
                'hash_norm' => $hash,
                'source' => $source,
                'lang' => $hl,
                'geo' => $gl,
                'seed_term' => $topic,
                'rank' => $rank,
                'score_auto' => $score,
                'confidence' => $confidence,
                'collected_at' => now(),
                'category' => $topic,
                'persona_id' => $personaId,
                'topic_id' => $topicId,  // ADDED
                'is_branded' => $isBranded,
                'topic_cluster' => $topic,
                'status' => 'new',
            ]) > 0;
        } catch (\Exception $e) {
            Log::warning("Failed to insert suggestion: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Enrich with SerpAPI People Also Ask
     * UPDATED: Now includes topic_id
     */
    private function enrichWithPAA(
        array $seeds,
        array $tokens,
        string $topic,
        int $topicId,      // ADDED
        int $personaId,
        string $hl,
        string $gl,
        int $paaPerSeed
    ): int {
        $generated = 0;
        $hasBrand = !empty($tokens['brand']);

        foreach ($seeds as $seed) {
            if (microtime(true) > SAVE_TOPICS_DEADLINE) {
                break;
            }

            $isBranded = !empty($seed['b']);
            $paaQuestions = $this->serpapi->getPeopleAlsoAsk($seed['q'], $hl, $gl);

            if (empty($paaQuestions)) {
                continue;
            }

            $rankP = 1;
            foreach ($paaQuestions as $question) {
                if ($isBranded && $hasBrand) {
                    if (!$this->brandTokens->hasToken($question, $tokens['brand']) ||
                        $this->brandTokens->hasToken($question, $tokens['competitors'])) {
                        continue;
                    }
                }

                if (!$isBranded && $hasBrand) {
                    if ($this->brandTokens->hasToken($question, $tokens['brand'])) {
                        continue;
                    }
                }

                [$normalized, $hash] = $this->normalizer->normalizeAndHash($question);
                
                $source = 'paa-serpapi' . ($isBranded ? '-branded' : '');

                try {
                    $inserted = DB::table('raw_suggestions')->insertOrIgnore([
                        'text' => $question,
                        'normalized' => $normalized,
                        'hash_norm' => $hash,
                        'source' => $source,
                        'lang' => $hl,
                        'geo' => $gl,
                        'seed_term' => $seed['q'],
                        'rank' => $rankP,
                        'score_auto' => 55,
                        'confidence' => 75,
                        'collected_at' => now(),
                        'category' => $topic,
                        'persona_id' => $personaId,
                        'topic_id' => $topicId,  // ADDED
                        'is_branded' => $isBranded,
                        'topic_cluster' => $topic,
                        'status' => 'new',
                    ]);

                    if ($inserted > 0) {
                        $generated++;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed to insert PAA suggestion: " . $e->getMessage());
                }

                $rankP++;
                if ($rankP > $paaPerSeed) {
                    break;
                }
            }
        }

        return $generated;
    }
    
    /**
     * Get single topic with personas
     */
    public function show($id)
    {
        try {
            $topic = Topic::with('personas')->findOrFail($id);
            
            return response()->json([
                'id' => $topic->id,
                'name' => $topic->name,
                'is_active' => $topic->is_active,
                'personas' => $topic->personas->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                ])
            ]);
        } catch (\Exception $e) {
            Log::error('Show topic error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}