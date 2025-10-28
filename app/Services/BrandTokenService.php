<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BrandTokenService
{
    /**
     * Get brand and competitor tokens for filtering
     */
    public function getBrandTokens(?string $brandId): array
    {
        $brand = [];
        $competitors = [];

        // Load all brands
        $brands = DB::table('brands')->select('id', 'name')->get();
        
        // Load all aliases
        $aliasesRaw = DB::table('brand_aliases')->select('brand_id', 'alias')->get();
        $byBrand = [];
        foreach ($aliasesRaw as $alias) {
            $byBrand[$alias->brand_id][] = $alias->alias;
        }

        // Build token lists
        foreach ($brands as $b) {
            $tokens = array_filter(array_map('trim', array_merge(
                [$b->id, $b->name],
                $byBrand[$b->id] ?? []
            )));

            if ($brandId !== null && (string)$b->id === (string)$brandId) {
                $brand = $tokens;
            } else {
                $competitors = array_merge($competitors, $tokens);
            }
        }

        // Normalize: lowercase, unique
        $normalize = function(array $arr) {
            $out = [];
            foreach ($arr as $token) {
                $key = mb_strtolower($token, 'UTF-8');
                if ($key !== '') {
                    $out[$key] = 1;
                }
            }
            return array_keys($out);
        };

        return [
            'brand' => $normalize($brand),
            'competitors' => $normalize($competitors),
            'slug' => $brandId,
        ];
    }

    /**
     * Check if a query contains any tokens from a list
     */
    public function hasToken(string $query, array $tokens): bool
    {
        $queryLower = ' ' . mb_strtolower($query, 'UTF-8') . ' ';
        
        foreach ($tokens as $token) {
            $tokenLower = ' ' . mb_strtolower($token, 'UTF-8') . ' ';
            if (str_contains($queryLower, $tokenLower)) {
                return true;
            }
        }

        return false;
    }
}