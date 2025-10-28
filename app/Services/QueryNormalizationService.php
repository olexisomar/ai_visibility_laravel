<?php

namespace App\Services;

class QueryNormalizationService
{
    /**
     * Normalize a query for deduplication
     */
    public function normalize(string $query): string
    {
        // 1) Lowercase
        $s = mb_strtolower($query, 'UTF-8');
        
        // 2) Trim
        $s = trim($s);
        
        // 3) Normalize accents (transliterate)
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        
        // 4) Collapse multiple spaces
        $s = preg_replace('/\s+/u', ' ', $s);
        
        // 5) Strip leading/trailing punctuation
        $s = trim($s, " \t\n\r\0\x0B.,;:!?'\"()[]{}");
        
        // 6) Convert thousand separators: "1,000" -> "1000"
        $s = preg_replace('/(?<=\d),(?=\d{3}\b)/', '', $s);
        
        return $s;
    }

    /**
     * Generate hash from normalized query
     */
    public function hash(string $normalized): string
    {
        return sha1($normalized);
    }

    /**
     * Normalize and hash in one call
     */
    public function normalizeAndHash(string $query): array
    {
        $normalized = $this->normalize($query);
        $hash = $this->hash($normalized);
        
        return [$normalized, $hash];
    }
}