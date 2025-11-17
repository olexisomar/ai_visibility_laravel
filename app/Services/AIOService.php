<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AIOService
{
    private int $concurrency;
    private int $pageSize;
    private int $rateGapMs;
    private int $httpTimeout;

    public function __construct()
    {
        $this->concurrency = max(1, (int)config('services.aio.concurrency')); // Lower for SerpAPI
        $this->pageSize = max(1, (int)config('services.aio.page_size'));
        $this->rateGapMs = max(0, (int)config('services.aio.rate_limit_ms'));
        $this->httpTimeout = max(5, (int)config('services.serpapi.timeout'));
    }

    /**
     * Run Google AIO monitoring
     */
    public function runAIOMonitoring(
        string $hl = 'en',
        string $gl = 'us',
        string $location = 'United States',
        int $offset = 0,
        ?int $accountId = null
    ): array {
        $serpApiKey = config('services.serpapi.key');
        if (!$serpApiKey) {
            throw new \RuntimeException('SERPAPI_KEY missing');
        }

        // Create run record
        $runId = DB::table('runs')->insertGetId([
            'account_id' => $accountId ?? session('account_id'),
            'model' => 'google-ai-overview',
            'temp' => null,
            'status' => 'running',
            'started_at' => now(),
        ]);

        // Load brands and aliases
        $brands = DB::table('brands')->orderBy('name')->get(['id', 'name'])->toArray();
        $aliasesRaw = DB::table('brand_aliases')->get(['brand_id', 'alias']);
        
        $aliasesBy = [];
        foreach ($aliasesRaw as $alias) {
            $aliasesBy[$alias->brand_id][] = $alias->alias;
        }

        $hasSentiment = $this->checkSentimentColumn();
        $hasSourceCol = $this->checkSourceColumn();

        $processed = 0;
        $errors = [];
        $usedIds = [];
        $lastPreview = '';

        // Page through all approved prompts
        do {
            $rows = DB::table('prompts')
                ->whereNull('deleted_at')
                ->whereIn('status', ['approved', 'active'])
                ->where(function($q) {
                    $q->where('is_paused', 0)
                    ->orWhereNull('is_paused');
                })
                ->orderBy('id')
                ->limit($this->pageSize)
                ->offset($offset)
                ->get(['id', 'category', 'prompt'])
                ->toArray();

            if (empty($rows)) {
                break;
            }

            // Process in waves
            for ($i = 0; $i < count($rows); $i += $this->concurrency) {
                $batch = array_slice($rows, $i, $this->concurrency);
                
                $results = $this->processBatch(
                    $batch,
                    $serpApiKey,
                    $hl,
                    $gl,
                    $location
                );

                // Save results
                foreach ($results as $idx => $result) {
                    $row = $batch[$idx];
                    
                    if (!$result['ok']) {
                        $errors[] = [
                            'prompt_id' => $row->id,
                            'error' => $result['error'] ?? 'Unknown error'
                        ];
                        continue;
                    }

                    $lastPreview = $this->saveResponse(
                        $runId,
                        $row,
                        $result,
                        $brands,
                        $aliasesBy,
                        $hasSentiment,
                        $hasSourceCol,
                        $accountId
                    );

                    $usedIds[] = $row->id;
                    $processed++;
                }

                // Rate limiting (important for SerpAPI!)
                if ($this->rateGapMs > 0) {
                    usleep($this->rateGapMs * 1000);
                }
            }

            $offset += $this->pageSize;
        } while (true);

        // Mark run as complete
        DB::table('runs')
            ->where('id', $runId)
            ->update([
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        
        // Send notification
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notificationService->notifyRunCompleted($runId, [
                'prompts_processed' => $processed,
                'mentions_found' => DB::table('mentions')
                    ->join('responses', 'mentions.response_id', '=', 'responses.id')
                    ->where('responses.run_id', $runId)
                    ->count(),
                'brands_mentioned' => DB::table('mentions')
                    ->join('responses', 'mentions.response_id', '=', 'responses.id')
                    ->where('responses.run_id', $runId)
                    ->distinct('mentions.brand_id')
                    ->count('mentions.brand_id'),
            ]);
        } catch (\Exception $e) {
            Log::error('Notification failed: ' . $e->getMessage());
        }
        return [
            'run_id' => $runId,
            'model' => 'google-ai-overview',
            'processed' => $processed,
            'errors' => $errors,
            'prompt_ids' => $usedIds,
            'preview' => $lastPreview,
            'done' => true,
        ];
    }

    /**
     * Process batch using SerpAPI
     */
    private function processBatch(
        array $batch,
        string $apiKey,
        string $hl,
        string $gl,
        string $location
    ): array {
        $mh = curl_multi_init();
        $handles = [];
        
        foreach ($batch as $idx => $row) {
            $ch = $this->buildSerpHandle($apiKey, $row->prompt, $hl, $gl, $location);
            $handles[$idx] = [
                'handle' => $ch,
                'start_time' => microtime(true),
            ];
            curl_multi_add_handle($mh, $ch);
        }

        // Execute all requests
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status !== CURLM_OK) {
                break;
            }
            curl_multi_select($mh, 0.2);
        } while ($running > 0);

        // Collect results
        $results = [];
        foreach ($handles as $idx => $info) {
            $ch = $info['handle'];
            $body = curl_multi_getcontent($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch) ?: null;
            $latencyMs = (microtime(true) - $info['start_time']) * 1000.0;

            if ($err || $http >= 400 || !$body) {
                $results[$idx] = [
                    'ok' => false,
                    'error' => $err ?: "HTTP $http",
                ];
            } else {
                $aio = $this->parseAIOFromSerp($body);
                $results[$idx] = [
                    'ok' => true,
                    'text' => $aio['text'],
                    'citations' => $aio['citations'],
                    'links' => $aio['links'],
                    'latency_ms' => (int)$latencyMs,
                ];
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Build SerpAPI cURL handle
     */
    private function buildSerpHandle(
        string $apiKey,
        string $query,
        string $hl,
        string $gl,
        string $location
    ) {
        $url = 'https://serpapi.com/search.json?' . http_build_query([
            'engine' => 'google',
            'q' => $query,
            'hl' => $hl,
            'gl' => $gl,
            'location' => $location,
            'no_cache' => 'true',
            'api_key' => $apiKey,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->httpTimeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->httpTimeout),
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
        ]);

        return $ch;
    }

    /**
     * Parse AI Overview from SerpAPI response
     */
    private function parseAIOFromSerp(string $body): array
    {
        $data = json_decode($body, true) ?: [];
        
        if (!empty($data['error'])) {
            return [
                'text' => '[SerpAPI error: ' . $data['error'] . ']',
                'citations' => [],
                'links' => []
            ];
        }

        $aio = $data['ai_overview'] ?? null;
        if (!$aio) {
            return [
                'text' => '[No AI Overview was triggered for this query]',
                'citations' => [],
                'links' => []
            ];
        }

        // Extract text
        $text = '';
        if (isset($aio['answer']) && is_string($aio['answer'])) {
            $text = trim($aio['answer']);
        }
        if ($text === '' && isset($aio['summary']) && is_string($aio['summary'])) {
            $text = trim($aio['summary']);
        }

        // Fallback to text_blocks
        if ($text === '' && !empty($aio['text_blocks']) && is_array($aio['text_blocks'])) {
            $text = $this->flattenAIOTextBlocks($aio['text_blocks']);
        }
        
        if ($text === '') {
            $text = '[AI Overview present, but no parsable text]';
        }

        // Extract citations
        $citations = [];
        if (!empty($aio['references']) && is_array($aio['references'])) {
            foreach ($aio['references'] as $c) {
                $citations[] = [
                    'title' => $c['title'] ?? '',
                    'link' => $c['link'] ?? '',
                    'source' => $c['source'] ?? ''
                ];
            }
        } elseif (!empty($aio['citations']) && is_array($aio['citations'])) {
            foreach ($aio['citations'] as $c) {
                $citations[] = [
                    'title' => $c['title'] ?? '',
                    'link' => $c['link'] ?? '',
                    'source' => $c['source'] ?? ''
                ];
            }
        }

        // Append sources to text
        if (!empty($citations)) {
            $srcs = array_map(function($c) {
                $title = $c['title'] ?: ($c['source'] ?? '');
                return $title . ' (' . ($c['link'] ?? '') . ')';
            }, $citations);
            $text .= "\n\nSources:\n- " . implode("\n- ", $srcs);
        }

        // Extract links
        $links = [];
        foreach ($citations as $c) {
            if (!empty($c['link'])) {
                $links[] = [
                    'url' => $c['link'],
                    'anchor' => $c['title'] ?: ($c['source'] ?? null),
                    'source' => $c['source'] ?? null
                ];
            }
        }

        // Scan text_blocks for embedded links
        if (!empty($aio['text_blocks']) && is_array($aio['text_blocks'])) {
            $this->extractLinksFromTextBlocks($aio['text_blocks'], $links);
        }

        // Deduplicate links
        $seen = [];
        $uniqueLinks = [];
        foreach ($links as $link) {
            if (!isset($seen[$link['url']])) {
                $seen[$link['url']] = true;
                $uniqueLinks[] = $link;
            }
        }

        return [
            'text' => $text,
            'citations' => $citations,
            'links' => $uniqueLinks
        ];
    }

    /**
     * Flatten AIO text blocks into readable text
     */
    private function flattenAIOTextBlocks(array $blocks): string
    {
        $out = [];
        
        $walk = function($node) use (&$walk, &$out) {
            if ($node === null) return;
            
            if (is_array($node) && !$this->isAssoc($node)) {
                foreach ($node as $n) $walk($n);
                return;
            }
            
            if (is_array($node)) {
                $type = $node['type'] ?? null;
                
                if (isset($node['snippet']) && is_string($node['snippet'])) {
                    $snippet = trim($node['snippet']);
                    if ($snippet !== '') $out[] = $snippet;
                }
                
                if (!empty($node['text_blocks']) && is_array($node['text_blocks'])) {
                    $walk($node['text_blocks']);
                }
                
                if ($type === 'list' && !empty($node['list']) && is_array($node['list'])) {
                    foreach ($node['list'] as $li) {
                        if (is_array($li)) {
                            if (isset($li['snippet']) && is_string($li['snippet'])) {
                                $snippet = trim($li['snippet']);
                                if ($snippet !== '') $out[] = '• ' . $snippet;
                            } elseif (!empty($li['text_blocks'])) {
                                $before = count($out);
                                $walk($li['text_blocks']);
                                if (count($out) > $before) {
                                    $out[count($out) - 1] = '• ' . $out[count($out) - 1];
                                }
                            }
                        }
                    }
                }
            }
        };
        
        $walk($blocks);
        $text = trim(implode("\n", $out));
        return preg_replace("/(\R){3,}/", "\n\n", $text);
    }

    /**
     * Extract links from text blocks
     */
    private function extractLinksFromTextBlocks(array $blocks, array &$links): void
    {
        $stack = $blocks;
        while (!empty($stack)) {
            $node = array_pop($stack);
            if (!is_array($node)) continue;

            if (isset($node['video']['link']) && is_string($node['video']['link'])) {
                $links[] = [
                    'url' => $node['video']['link'],
                    'anchor' => $node['video']['source'] ?? ($node['snippet'] ?? 'Video'),
                    'source' => $node['video']['source'] ?? null
                ];
            }

            if (!empty($node['text_blocks']) && is_array($node['text_blocks'])) {
                foreach ($node['text_blocks'] as $n) $stack[] = $n;
            }

            if (($node['type'] ?? null) === 'list' && !empty($node['list']) && is_array($node['list'])) {
                foreach ($node['list'] as $li) $stack[] = $li;
            }
        }
    }

    /**
     * Check if array is associative
     */
    private function isAssoc(array $arr): bool
    {
        $keys = array_keys($arr);
        return $keys !== range(0, count($arr) - 1);
    }

    /**
     * Save AIO response
     */
    private function saveResponse(
        int $runId,
        $row,
        array $result,
        array $brands,
        array $aliasesBy,
        bool $hasSentiment,
        bool $hasSourceCol,
        ?int $accountId = null
    ): string {
        $text = $result['text'];
        $intent = $this->classifyIntent($text);

        // Insert response
        $responseId = DB::table('responses')->insertGetId([
            'account_id' => $accountId,
            'run_id' => $runId,
            'prompt_id' => $row->id,
            'raw_answer' => $text,
            'latency_ms' => $result['latency_ms'],
            'tokens_in' => null,
            'tokens_out' => null,
            'prompt_text' => $row->prompt,
            'prompt_category' => $row->category ?? null,
            'intent' => $intent,
            'created_at' => now(),
        ]);

        // ✅ NEW: Extract text-only version (no URLs)
        $textOnly = preg_replace('~https?://\S+~u', ' ', $text);
        $textOnly = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $textOnly);

        // Detect brand mentions IN TEXT ONLY
        foreach ($brands as $brand) {
            foreach ($aliasesBy[$brand->id] ?? [] as $alias) {
                $pattern = '/\b' . preg_quote($alias, '/') . '\b/iu';
                
                if (preg_match($pattern, $textOnly)) {
                    $mentionData = [
                        'account_id' => $accountId,
                        'response_id' => $responseId,
                        'brand_id' => $brand->id,
                        'found_alias' => $alias,
                    ];

                    if ($hasSentiment) {
                        $sentiment = $this->detectSentiment($textOnly);
                        $mentionData['sentiment'] = $sentiment;
                    }

                    DB::table('mentions')->insert($mentionData);
                    break;
                }
            }
        }

        // Save links from AIO
        foreach ($result['links'] as $link) {
            $linkData = [
                'account_id' => $accountId,
                'response_id' => $responseId,
                'url' => $link['url'],
                'anchor' => $link['anchor'] ?? null,
            ];

            if ($hasSourceCol) {
                $linkData['source'] = $link['source'] ?? null;
            }

            DB::table('response_links')->insertOrIgnore($linkData);
        }

        // Also extract plain links from text
        $plainLinks = $this->extractLinksFromText($text);
        foreach ($plainLinks as $link) {
            $linkData = [
                'account_id' => $accountId,
                'response_id' => $responseId,
                'url' => $link['url'],
                'anchor' => $link['anchor'] ?? null,
            ];

            if ($hasSourceCol) {
                $linkData['source'] = null;
            }

            DB::table('response_links')->insertOrIgnore($linkData);
        }

        return mb_substr($text, 0, 400, 'UTF-8');
    }

    /**
     * Classify query intent
     */
    private function classifyIntent(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        
        $transactional = ['sign up', 'signup', 'register', 'create account', 'deposit', 'bonus', 'promo code', 'odds', 'betting lines', 'parlay', 'spread', 'moneyline', 'over/under', 'price', 'buy', 'subscribe'];
        foreach ($transactional as $w) {
            if (str_contains($t, $w)) return 'transactional';
        }
        
        $navigational = ['official site', 'website', 'visit', 'go to', 'login', 'contact us', 'download app', 'open the app', 'find on', 'navigate to'];
        foreach ($navigational as $w) {
            if (str_contains($t, $w)) return 'navigational';
        }
        
        $informational = ['how to', 'what is', 'guide', 'explained', 'tips', 'tutorial', 'compare', 'vs.', 'best', 'review', 'pros and cons', 'definition', 'meaning'];
        foreach ($informational as $w) {
            if (str_contains($t, $w)) return 'informational';
        }
        
        return 'other';
    }

    /**
     * AI-powered sentiment detection with keyword fallback
     */
    private function detectSentiment(string $text): string
    {
        try {
            // Remove URLs and clean text first
            $cleanText = preg_replace('~https?://\S+~u', ' ', $text);
            $cleanText = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $cleanText);
            $cleanText = trim(preg_replace('/\s+/', ' ', $cleanText));
            
            // Limit context to 500 chars
            if (mb_strlen($cleanText, 'UTF-8') > 500) {
                $cleanText = mb_substr($cleanText, 0, 500, 'UTF-8');
            }
            
            if (mb_strlen($cleanText, 'UTF-8') < 10) {
                return 'neutral';
            }
            
            $apiKey = config('services.openai.key');
            if (!$apiKey) {
                return $this->detectSentimentKeyword($cleanText);
            }
            
            $model = config('services.openai.model');
            
            $payload = [
                'model' => $model,
                'temperature' => 0,
                'max_tokens' => 10,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Analyze the sentiment of the text about a brand. Reply with ONLY one word: positive, negative, or neutral. No explanations.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $cleanText
                    ]
                ]
            ];
            
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ]);
            
            $response = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($http === 200 && $response) {
                $data = json_decode($response, true);
                $sentiment = strtolower(trim($data['choices'][0]['message']['content'] ?? ''));
                
                if (str_contains($sentiment, 'positive')) return 'positive';
                if (str_contains($sentiment, 'negative')) return 'negative';
                if (str_contains($sentiment, 'neutral')) return 'neutral';
                
                if (in_array($sentiment, ['positive', 'negative', 'neutral'])) {
                    return $sentiment;
                }
            }
            
            if ($http !== 200) {
                Log::warning('AI sentiment API error (AIO)', [
                    'http' => $http,
                    'error' => $error
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('AI sentiment exception (AIO): ' . $e->getMessage());
        }
        
        return $this->detectSentimentKeyword($text);
    }

    /**
     * Improved keyword-based sentiment (fallback)
     */
    private function detectSentimentKeyword(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        
        if (preg_match('/\b(scam|fraud|steal|cheat|criminal|illegal|rip\s*off)\b/', $t)) {
            return 'negative';
        }
        
        if (preg_match('/\b(not|no|never|don\'t|doesn\'t|isn\'t|aren\'t|wasn\'t|won\'t)\s+\w*\s*(great|good|excellent|best|amazing|reliable|safe|fast|helpful)\b/i', $t)) {
            return 'negative';
        }
        
        $strongPositive = ['excellent', 'amazing', 'outstanding', 'fantastic', 'superb', 'phenomenal'];
        foreach ($strongPositive as $w) {
            if (str_contains($t, $w)) return 'positive';
        }
        
        $strongNegative = ['terrible', 'awful', 'horrible', 'worst', 'disgusting', 'pathetic'];
        foreach ($strongNegative as $w) {
            if (str_contains($t, $w)) return 'negative';
        }
        
        $positive = ['great', 'best', 'love', 'safe', 'reliable', 'fast', 'helpful', 'win', 'easy', 'smooth', 'quick', 'recommend', 'trust'];
        $posCount = 0;
        foreach ($positive as $w) {
            if (str_contains($t, $w)) $posCount++;
        }
        
        $negative = ['bad', 'hate', 'slow', 'unsafe', 'unreliable', 'lose', 'difficult', 'complicated', 'avoid', 'warning', 'problem', 'issue'];
        $negCount = 0;
        foreach ($negative as $w) {
            if (str_contains($t, $w)) $negCount++;
        }
        
        if ($posCount > $negCount && $posCount > 0) return 'positive';
        if ($negCount > $posCount && $negCount > 0) return 'negative';
        
        return 'neutral';
    }

    /**
     * Extract links from text
     */
    private function extractLinksFromText(?string $text): array
    {
        if (!$text) return [];
        
        $links = [];
        
        // Markdown links
        if (preg_match_all('/\[(.*?)\]\((https?:\/\/[^\s)]+)\)/iu', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $links[] = [
                    'url' => $match[2],
                    'anchor' => trim($match[1]) ?: null
                ];
            }
        }
        
        // Plain URLs
        if (preg_match_all('/(?<!\()(?<!\[)(https?:\/\/[^\s<>"\)\]]+)/iu', $text, $matches)) {
            foreach ($matches[1] as $url) {
                $links[] = [
                    'url' => $url,
                    'anchor' => null
                ];
            }
        }
        
        // Deduplicate
        $seen = [];
        $unique = [];
        foreach ($links as $link) {
            if (!isset($seen[$link['url']])) {
                $seen[$link['url']] = true;
                $unique[] = $link;
            }
        }
        
        return $unique;
    }

    private function checkSentimentColumn(): bool
    {
        try {
            return (bool)DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'mentions'
                  AND COLUMN_NAME = 'sentiment'
            ")->cnt;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkSourceColumn(): bool
    {
        try {
            return (bool)DB::selectOne("
                SELECT COUNT(*) as cnt
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'response_links'
                  AND COLUMN_NAME = 'source'
            ")->cnt;
        } catch (\Exception $e) {
            return false;
        }
    }
}