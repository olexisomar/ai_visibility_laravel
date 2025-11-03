<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitoringService
{
    private int $concurrency;
    private int $pageSize;
    private int $rateGapMs;
    private int $httpTimeout;

    public function __construct()
    {
        $this->concurrency = max(1, (int)env('RUN_CONCURRENCY', 6));
        $this->pageSize = max(1, (int)env('RUN_PAGE_SIZE', 200));
        $this->rateGapMs = max(0, (int)env('RATE_LIMIT_MS', 0));
        $this->httpTimeout = max(5, (int)env('OPENAI_HTTP_TIMEOUT', 60));
    }

    /**
     * Run monitoring for all approved prompts
     */
    public function runMonitoring(string $model, float $temp = 0.2, int $offset = 0): array
    {
        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY missing');
        }

        // Create run record
        $runId = DB::table('runs')->insertGetId([
            'model' => $model,
            'temp' => $temp,
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

        // Check if mentions table has sentiment column
        $hasSentiment = $this->checkSentimentColumn();

        $processed = 0;
        $errors = [];
        $usedIds = [];

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
                break; // No more prompts
            }

            // Process this page in waves
            for ($i = 0; $i < count($rows); $i += $this->concurrency) {
                $batch = array_slice($rows, $i, $this->concurrency);
                
                $results = $this->processBatch(
                    $batch,
                    $apiKey,
                    $model,
                    $temp,
                    $this->httpTimeout
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

                    $this->saveResponse(
                        $runId,
                        $row,
                        $result,
                        $brands,
                        $aliasesBy,
                        $hasSentiment
                    );

                    $usedIds[] = $row->id;
                    $processed++;
                }

                // Rate limiting
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
            'model' => $model,
            'processed' => $processed,
            'errors' => $errors,
            'prompt_ids' => $usedIds,
            'done' => true,
        ];
    }

    /**
     * Process a batch of prompts concurrently
     */
    private function processBatch(array $batch, string $apiKey, string $model, float $temp, int $timeout): array
    {
        $mh = curl_multi_init();
        $handles = [];
        
        foreach ($batch as $idx => $row) {
            $ch = $this->buildOpenAIHandle($apiKey, $model, $temp, $row->prompt, $timeout);
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
                $data = json_decode($body, true);
                $results[$idx] = [
                    'ok' => true,
                    'answer' => $data['choices'][0]['message']['content'] ?? '',
                    'tokens_in' => (int)($data['usage']['prompt_tokens'] ?? 0),
                    'tokens_out' => (int)($data['usage']['completion_tokens'] ?? 0),
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
     * Build cURL handle for OpenAI API
     */
    private function buildOpenAIHandle(string $apiKey, string $model, float $temp, string $prompt, int $timeout)
    {
        $url = 'https://api.openai.com/v1/chat/completions';
        $payload = [
            'model' => $model,
            'temperature' => $temp,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Answer concisely and include source URLs when you reference facts, products, or brands. Use [Title](https://...) markdown when possible.'
                ],
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$apiKey}",
                "Content-Type: application/json"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
        ]);

        return $ch;
    }

    /**
     * Save response and detect mentions
     */
    private function saveResponse(
        int $runId,
        $row,
        array $result,
        array $brands,
        array $aliasesBy,
        bool $hasSentiment
    ): void {
        $answer = $result['answer'];
        $intent = $this->classifyIntent($answer);

        // Insert response
        $responseId = DB::table('responses')->insertGetId([
            'run_id' => $runId,
            'prompt_id' => $row->id,
            'raw_answer' => $answer,
            'latency_ms' => $result['latency_ms'],
            'tokens_in' => $result['tokens_in'],
            'tokens_out' => $result['tokens_out'],
            'prompt_text' => $row->prompt,
            'prompt_category' => $row->category ?? null,
            'intent' => $intent,
            'created_at' => now(),
        ]);

        // Detect brand mentions IN TEXT ONLY (not URLs)
        $textOnly = preg_replace('~https?://\S+~u', ' ', $answer);
        $textOnly = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $textOnly);
        $normalized = $this->normalize($textOnly);

        foreach ($brands as $brand) {
            foreach ($aliasesBy[$brand->id] ?? [] as $alias) {
                $needle = ' ' . trim($this->normalize($alias)) . ' ';
                
                if (str_contains($normalized, $needle)) {
                    $mentionData = [
                        'response_id' => $responseId,
                        'brand_id' => $brand->id,
                        'found_alias' => $alias,
                    ];

                    if ($hasSentiment) {
                        $sentiment = $this->detectSentiment(
                            $this->getContext($textOnly, $alias, 600)
                        );
                        $mentionData['sentiment'] = $sentiment;
                    }

                    DB::table('mentions')->insert($mentionData);
                    break;
                }
            }
        }

        // Extract and save links
        $links = $this->extractLinks($answer);
        foreach ($links as $link) {
            DB::table('response_links')->insertOrIgnore([
                'response_id' => $responseId,
                'url' => $link['url'],
                'anchor' => $link['anchor'],
            ]);
        }
    }

    /**
     * Normalize text for matching
     */
    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
        return ' ' . trim($s) . ' ';
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
            
            // Limit context to 500 chars to save tokens
            if (mb_strlen($cleanText, 'UTF-8') > 500) {
                $cleanText = mb_substr($cleanText, 0, 500, 'UTF-8');
            }
            
            // Skip if text is too short
            if (mb_strlen($cleanText, 'UTF-8') < 10) {
                return 'neutral';
            }
            
            $apiKey = env('OPENAI_API_KEY');
            if (!$apiKey) {
                return $this->detectSentimentKeyword($cleanText);
            }
            
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');
            
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
                
                // Extract just the sentiment word if there's extra text
                if (str_contains($sentiment, 'positive')) {
                    return 'positive';
                }
                if (str_contains($sentiment, 'negative')) {
                    return 'negative';
                }
                if (str_contains($sentiment, 'neutral')) {
                    return 'neutral';
                }
                
                // Validate it's one of our expected values
                if (in_array($sentiment, ['positive', 'negative', 'neutral'])) {
                    return $sentiment;
                }
            }
            
            // Log failures for monitoring
            if ($http !== 200) {
                Log::warning('AI sentiment API error', [
                    'http' => $http,
                    'error' => $error,
                    'response' => substr($response ?? '', 0, 200)
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('AI sentiment exception: ' . $e->getMessage());
        }
        
        // Fallback to improved keyword-based
        return $this->detectSentimentKeyword($text);
    }

    /**
     * Improved keyword-based sentiment (fallback)
     */
    private function detectSentimentKeyword(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        
        // Strong negative indicators (scams, fraud)
        if (preg_match('/\b(scam|fraud|steal|cheat|criminal|illegal|rip\s*off)\b/', $t)) {
            return 'negative';
        }
        
        // Check for negations ("not great", "no good")
        if (preg_match('/\b(not|no|never|don\'t|doesn\'t|isn\'t|aren\'t|wasn\'t|won\'t)\s+\w*\s*(great|good|excellent|best|amazing|reliable|safe|fast|helpful)\b/i', $t)) {
            return 'negative';
        }
        
        // Strong positive keywords
        $strongPositive = ['excellent', 'amazing', 'outstanding', 'fantastic', 'superb', 'phenomenal'];
        foreach ($strongPositive as $w) {
            if (str_contains($t, $w)) return 'positive';
        }
        
        // Strong negative keywords
        $strongNegative = ['terrible', 'awful', 'horrible', 'worst', 'disgusting', 'pathetic'];
        foreach ($strongNegative as $w) {
            if (str_contains($t, $w)) return 'negative';
        }
        
        // Regular positive keywords
        $positive = ['great', 'best', 'love', 'safe', 'reliable', 'fast', 'helpful', 'win', 'easy', 'smooth', 'quick', 'recommend', 'trust'];
        $posCount = 0;
        foreach ($positive as $w) {
            if (str_contains($t, $w)) $posCount++;
        }
        
        // Regular negative keywords
        $negative = ['bad', 'hate', 'slow', 'unsafe', 'unreliable', 'lose', 'difficult', 'complicated', 'avoid', 'warning', 'problem', 'issue'];
        $negCount = 0;
        foreach ($negative as $w) {
            if (str_contains($t, $w)) $negCount++;
        }
        
        // Decision based on counts
        if ($posCount > $negCount && $posCount > 0) return 'positive';
        if ($negCount > $posCount && $negCount > 0) return 'negative';
        
        return 'neutral';
    }

    /**
     * Get context around brand mention (excluding URLs)
     */
    private function getContext(string $answer, string $alias = '', int $limit = 600): string
    {
        // Remove URLs FIRST
        $text = preg_replace('~https?://\S+~u', ' ', $answer);
        
        // Remove markdown links
        $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $text);
        
        // Clean up whitespace
        $text = preg_replace('/\s+/u', ' ', trim($text));
        
        if ($alias !== '') {
            $pos = mb_stripos($text, $alias, 0, 'UTF-8');
            if ($pos !== false) {
                $start = max(0, $pos - (int)floor($limit / 2));
                return mb_substr($text, $start, $limit, 'UTF-8');
            }
        }
        
        return mb_substr($text, 0, $limit, 'UTF-8');
    }

    /**
     * Extract links from text (markdown and plain URLs)
     */
    private function extractLinks(?string $text): array
    {
        if (!$text) return [];
        
        $links = [];
        
        // Markdown links: [text](url)
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

    /**
     * Check if mentions table has sentiment column
     */
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
}