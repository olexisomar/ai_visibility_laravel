<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SerpAPIService
{
    private static array $cache = [];
    private static int $callCount = 0;

    /**
     * Get People Also Ask questions from SerpAPI
     */
    public function getPeopleAlsoAsk(string $query, string $hl = 'en', string $gl = 'us'): array
    {
        $apiKey = env('SERPAPI_KEY');
        if (!$apiKey) {
            return [];
        }

        // Budget check
        $budget = (int)(env('SERPAPI_PAA_BUDGET', 10));
        if (self::$callCount >= $budget) {
            return [];
        }

        // Cache check
        $cacheKey = strtolower($query) . '|' . $hl . '|' . $gl;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // Deadline check (if defined)
        if (defined('SAVE_TOPICS_DEADLINE') && microtime(true) > constant('SAVE_TOPICS_DEADLINE')) {
            return [];
        }

        self::$callCount++;

        // Build URL
        $url = 'https://serpapi.com/search.json?engine=google'
             . '&q=' . rawurlencode($query)
             . '&hl=' . rawurlencode($hl)
             . '&gl=' . rawurlencode($gl)
             . '&api_key=' . rawurlencode($apiKey);

        // Make request
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'ai-visibility-company/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_FAILONERROR => false,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);

        $res = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($res === false) {
            Log::warning('[SerpAPI] cURL error: ' . curl_error($ch));
            curl_close($ch);
            return self::$cache[$cacheKey] = [];
        }
        
        curl_close($ch);

        if ($http >= 400) {
            Log::warning("[SerpAPI] HTTP $http for query: $query");
            return self::$cache[$cacheKey] = [];
        }

        // Parse response
        $json = json_decode($res, true);
        if (!is_array($json)) {
            return self::$cache[$cacheKey] = [];
        }

        $paa = (array)($json['related_questions'] ?? []);
        $results = [];
        
        foreach ($paa as $row) {
            $question = trim((string)($row['question'] ?? ''));
            if ($question !== '') {
                $results[] = $question;
            }
        }

        // De-duplicate (case-insensitive)
        $seen = [];
        $final = [];
        foreach ($results as $q) {
            $key = mb_strtolower($q, 'UTF-8');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $final[] = $q;
            }
        }

        return self::$cache[$cacheKey] = $final;
    }

    /**
     * Reset call counter (useful for testing)
     */
    public static function resetCallCount(): void
    {
        self::$callCount = 0;
    }
}