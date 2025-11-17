<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class LLMService
{
    private array $geminiCircuitBreaker = ['fails' => 0, 'until' => 0];
    private string $circuitBreakerFile;

    public function __construct()
    {
        $this->circuitBreakerFile = sys_get_temp_dir() . '/gemini_circuit.json';
        $this->loadCircuitBreaker();
    }

    /**
     * Get configured LLM providers from .env
     */
    public function getProviders(): array
    {
        $openaiKey = config('services.openai.key');
        $geminiKey = config('services.gemini.key');

        $providers = [];

        if ($openaiKey) {
            $providers[] = [
                'provider' => 'openai',
                'model' => config('services.openai.model'),
            ];
        }

        if ($geminiKey && !$this->isGeminiBlocked()) {
            $providers[] = [
                'provider' => 'gemini',
                'model' => config('services.gemini.model'),
            ];
        }

        if (empty($providers)) {
            throw new RuntimeException('No LLM credentials. Set OPENAI_API_KEY or GEMINI_API_KEY in .env');
        }

        return $providers;
    }

    /**
     * Generate queries using OpenAI or Gemini
     */
    public function generateQueries(
        array $provider,
        string $topic,
        array $persona,
        array $brandTokens,
        string $hl = 'en',
        string $gl = 'us'
    ): array {
        [$system, $user] = $this->buildPrompt($topic, $persona, $brandTokens, $hl, $gl);

        if ($provider['provider'] === 'openai') {
            return $this->callOpenAI($provider['model'], $system, $user);
        } else {
            return $this->callGemini($provider['model'], $system, $user);
        }
    }

    /**
     * Build LLM prompt from persona and topic
     */
    private function buildPrompt(
        string $topic,
        array $persona,
        array $brandTokens,
        string $hl,
        string $gl
    ): array {
        $system = "You generate realistic search queries a specific persona would actually type into a search engine. "
                . "Return strict JSON with two arrays: {\"generic\": string[], \"branded\": string[]}. "
                . "Do not include duplicates or empty strings. No explanations.";

        $description = $persona['description'] ?? '';
        $attributes = $persona['attributes'] ?? null;
        $attributesJson = is_array($attributes)
            ? json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string)$attributes;

        $brand = $brandTokens['brand'];
        $competitors = $brandTokens['competitors'];
        
        $brandLine = $brand
            ? ("Persona brand tokens: " . implode(', ', $brand) . ". Competitor tokens: " . implode(', ', array_slice($competitors, 0, 30)) . ".")
            : "Persona brand tokens: none.";

        $user = "Persona description:\n{$description}\n\n"
              . "Persona attributes JSON (may be empty): {$attributesJson}\n"
              . "Language: {$hl}, Country: {$gl}\n"
              . "{$brandLine}\n\n"
              . "Topic: {$topic}\n\n"
              . "Task:\n"
              . "1) Produce 1 generic queries for this persona's intent on the topic. NO brand names in generic.\n"
              . "2) Produce 1 branded queries ONLY if persona has a brand: each MUST include exactly one of the brand tokens, and must NOT include competitor tokens.\n"
              . "3) Mix informational/transactional as fits persona. Desktop bias if persona prefers desktop/tablet.\n"
              . "4) Plain search queries only (no punctuation at end, no quotes).";

        return [$system, $user];
    }

    /**
     * Call OpenAI API
     */
    private function callOpenAI(string $model, string $system, string $user): array
    {
        $key = config('services.openai.key');
        
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'Connection: close'
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'temperature' => 0.2,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user]
                ],
                'response_format' => ['type' => 'json_object']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $res = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $http >= 400) {
            Log::error("OpenAI HTTP $http: " . substr($res, 0, 200));
            throw new RuntimeException("OpenAI HTTP $http");
        }

        $json = json_decode((string)$res, true);
        $text = (string)($json['choices'][0]['message']['content'] ?? '{}');
        $obj = json_decode($text, true);

        return [
            'generic' => array_values(array_filter((array)($obj['generic'] ?? []), 'is_string')),
            'branded' => array_values(array_filter((array)($obj['branded'] ?? []), 'is_string')),
        ];
    }

    /**
     * Call Gemini API with circuit breaker
     */
    private function callGemini(string $model, string $system, string $user): array
    {
        $key = config('services.gemini.key');
        if (!$key) {
            throw new RuntimeException('GEMINI_API_KEY missing');
        }

        // Normalize model
        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }
        
        $aliases = [
            'gemini-1.5-flash' => 'gemini-2.0-flash',
            'gemini-1.5-flash-latest' => 'gemini-2.0-flash',
            'gemini-1.5-pro' => 'gemini-2.5-pro',
        ];
        $model = $aliases[$model] ?? $model;

        $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key=" . rawurlencode($key);

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => "Return ONLY raw JSON with this exact shape and nothing else:\n"
                            . "{\"generic\": string[], \"branded\": string[]}\n\n"
                            . "Rules: no code fences, no Markdown, no explanations, no comments.\n\n"
                            . $system . "\n\n" . $user
                ]]
            ]],
            'generationConfig' => ['temperature' => 0.2],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Connection: close'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $res = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        // Circuit breaker logic
        if ($res === false || $http >= 400) {
            $this->recordGeminiFailure($errno, $http, $totalTime);
            throw new RuntimeException("Gemini HTTP $http");
        }

        // Parse response
        $json = json_decode((string)$res, true) ?: [];
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        
        $texts = [];
        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text'])) {
                $texts[] = (string)$part['text'];
            }
        }
        $text = trim(implode("\n", $texts));

        // Strip code fences
        if (preg_match('/^\s*```[a-zA-Z]*\s*(.*?)\s*```/s', $text, $m)) {
            $text = trim($m[1]);
        }

        $obj = json_decode($text, true);
        if (!is_array($obj) && preg_match('/\{.*\}/s', $text, $m)) {
            $obj = json_decode($m[0], true);
        }

        if (is_array($obj)) {
            // Success - reset circuit breaker
            $this->resetCircuitBreaker();
            
            return [
                'generic' => array_values(array_filter((array)($obj['generic'] ?? []), 'is_string')),
                'branded' => array_values(array_filter((array)($obj['branded'] ?? []), 'is_string')),
            ];
        }

        Log::warning('[Gemini] Non-JSON response: ' . substr($text, 0, 200));
        return ['generic' => [], 'branded' => []];
    }

    // Circuit breaker methods
    private function loadCircuitBreaker(): void
    {
        if (file_exists($this->circuitBreakerFile)) {
            $data = json_decode(file_get_contents($this->circuitBreakerFile), true);
            if (is_array($data)) {
                $this->geminiCircuitBreaker = $data;
            }
        }
    }

    private function saveCircuitBreaker(): void
    {
        file_put_contents(
            $this->circuitBreakerFile,
            json_encode($this->geminiCircuitBreaker)
        );
    }

    private function isGeminiBlocked(): bool
    {
        return (int)($this->geminiCircuitBreaker['until'] ?? 0) > time();
    }

    private function recordGeminiFailure(int $errno, int $http, float $totalTime): void
    {
        $silentTimeout = ($errno === 0 && $http === 0 && $totalTime >= 14.5);
        $transportFail = ($errno !== 0) || $silentTimeout;

        if ($transportFail) {
            $this->geminiCircuitBreaker['fails'] = ($this->geminiCircuitBreaker['fails'] ?? 0) + 1;
            
            if ($this->geminiCircuitBreaker['fails'] >= 2) {
                $this->geminiCircuitBreaker['until'] = time() + 120;
                Log::warning('[Gemini] Circuit breaker OPENED - blocking for 120s');
            }
            
            $this->saveCircuitBreaker();
        }
    }

    private function resetCircuitBreaker(): void
    {
        $this->geminiCircuitBreaker = ['fails' => 0, 'until' => 0];
        $this->saveCircuitBreaker();
    }
}