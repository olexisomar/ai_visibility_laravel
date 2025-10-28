<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class GSCService
{
    /**
     * Get token weights from GSC queries for scoring
     */
    public function getWeights(?string $property = null, int $limit = 500): array
    {
        if ($property) {
            $rows = DB::table('gsc_queries')
                ->where('property', $property)
                ->select('query', DB::raw('SUM(impressions) as imp'))
                ->groupBy('query')
                ->orderByDesc('imp')
                ->limit($limit)
                ->pluck('imp', 'query')
                ->toArray();
        } else {
            $rows = DB::table('gsc_queries')
                ->select('query', DB::raw('SUM(impressions) as imp'))
                ->groupBy('query')
                ->orderByDesc('imp')
                ->limit($limit)
                ->pluck('imp', 'query')
                ->toArray();
        }

        // Tokenize and build weights
        $weights = [];
        foreach ($rows as $query => $impressions) {
            $tokens = $this->tokenizeSimple($query);
            foreach ($tokens as $token) {
                $weights[$token] = ($weights[$token] ?? 0) + (int)$impressions;
            }
        }

        arsort($weights);
        return $weights;
    }

    /**
     * Score a query based on GSC token weights
     */
    public function scoreQuery(string $query, array $weights): int
    {
        $sum = 0;
        $seen = [];
        
        foreach ($this->tokenizeSimple($query) as $token) {
            if (isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            
            if (isset($weights[$token])) {
                $sum += (int)$weights[$token];
            }
        }

        return $sum;
    }

    /**
     * Simple tokenization (lowercase, alphanumeric + hyphens)
     */
    private function tokenizeSimple(string $s): array
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9\s\-]/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', trim($s));
        
        return $s === '' ? [] : explode(' ', $s);
    }

    /**
     * Get primary GSC property from settings
     */
    public function getPrimaryProperty(): ?string
    {
        return DB::table('settings')
            ->where('key', 'gsc_primary_property')
            ->value('value');
    }
}