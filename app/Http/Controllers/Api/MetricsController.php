<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MetricsController extends Controller
{
    public function index(Request $request)
    {
        $scope = $request->input('scope', 'latest_per_source');
        // Check if we have any runs
        $hasRuns = DB::table('runs')->exists();
        
        if (!$hasRuns) {
            return response()->json([
                'scope' => $scope,
                'run_set' => [],
                'kpis' => [
                    'visibility' => 0,
                    'coverage_rate' => 0,
                    'share_of_mentions' => 0,
                    'missed_opportunities' => 0,
                    'mentions_by_brand' => [],
                ],
                'sentiment' => [
                    'positive' => 0,
                    'neutral' => 0,
                    'negative' => 0,
                    'total' => 0,
                    'positive_share' => 0,
                    'negative_share' => 0,
                ],
                'intent_mix' => [
                    'informational' => 0,
                    'navigational' => 0,
                    'transactional' => 0,
                    'other' => 0
                ],
                'intent_by_brand' => [],
                'comp_share' => ['total' => 0, 'by_brand' => []],
                'missed_prompts' => [],
            ]);
        }
        
        // Determine which runs to include
        $runFilterData = $this->getRunFilter($scope);
        $runFilterSQL = $runFilterData['sql'];
        $runFilterIDs = $runFilterData['ids'];

        // Get primary brand
        $primaryBrand = Setting::get('primary_brand_id', '');

        // Total responses in scope
        $totalResponses = $this->getTotalResponses($runFilterSQL, $runFilterIDs);

        // Visibility (responses with primary brand mention / total)
        $withMention = 0;
        if ($primaryBrand) {
            $withMention = $this->getResponsesWithBrandMention($primaryBrand, $runFilterSQL, $runFilterIDs);
        }
        $visibility = $totalResponses > 0 ? round(100 * $withMention / $totalResponses, 1) : 0;

        // Coverage (prompts with responses / total prompts)
        $coveredPrompts = $this->getCoveredPrompts($runFilterSQL, $runFilterIDs);
        $totalPrompts = DB::table('prompts')->whereNull('deleted_at')->count();
        $coverageRate = $totalPrompts > 0 ? min(1, $coveredPrompts / $totalPrompts) : 0;

        // Share of mentions
        $mentionsTotal = $this->getTotalMentions($runFilterSQL, $runFilterIDs);
        $ourMentions = 0;
        if ($primaryBrand) {
            $ourMentions = $this->getBrandMentions($primaryBrand, $runFilterSQL, $runFilterIDs);
        }
        $shareOfMentions = $mentionsTotal > 0 ? ($ourMentions / $mentionsTotal) : 0;

        // Sentiment breakdown
        $sentiment = $this->getSentiment($primaryBrand, $runFilterSQL, $runFilterIDs);

        // Intent mix
        $intentMix = $this->getIntentMix($runFilterSQL, $runFilterIDs);

        // Intent by brand
        $intentByBrand = $this->getIntentByBrand($runFilterSQL, $runFilterIDs);

        // Competitive share
        $compShare = $this->getCompetitiveShare($runFilterSQL, $runFilterIDs);

        // Mentions by brand
        $mentionsByBrand = $this->getMentionsByBrand($runFilterSQL, $runFilterIDs);

        // Missed opportunities
        $missedPrompts = [];
        if ($primaryBrand) {
            $missedPrompts = $this->getMissedOpportunities($primaryBrand, $runFilterSQL, $runFilterIDs);
        }

        // Get run info
        $usedRuns = [];
        if (!empty($runFilterIDs)) {
            $usedRuns = DB::table('runs')
                ->whereIn('id', $runFilterIDs)
                ->orderBy('id', 'desc')
                ->select('id', 'model', 'run_at')
                ->get()
                ->toArray();
        }

        return response()->json([
            'scope' => $scope,
            'run_set' => $usedRuns,
            'kpis' => [
                'visibility' => $visibility,
                'coverage_rate' => $coverageRate,
                'share_of_mentions' => $shareOfMentions,
                'missed_opportunities' => count($missedPrompts),
                'mentions_by_brand' => $mentionsByBrand,
            ],
            'sentiment' => $sentiment,
            'intent_mix' => $intentMix,
            'intent_by_brand' => $intentByBrand,
            'comp_share' => $compShare,
            'missed_prompts' => $missedPrompts,
        ]);
    }

    private function getRunFilter(string $scope): array
    {
        if ($scope === 'all') {
            return ['sql' => null, 'ids' => []];
        }

        if ($scope === 'latest') {
            $id = DB::table('runs')->max('id');
            return $id ? ['sql' => 'r.run_id = ?', 'ids' => [$id]] : ['sql' => '0', 'ids' => []];
        }

        // Default: latest_per_source
        $gptId = DB::table('runs')->where('model', 'like', 'gpt%')->max('id');
        $aioId = DB::table('runs')->where('model', 'google-ai-overview')->max('id');
        
        $ids = array_values(array_filter([$gptId, $aioId]));
        if (empty($ids)) {
            return ['sql' => '1=0', 'ids' => []];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return ['sql' => "r.run_id IN ($placeholders)", 'ids' => $ids];
    }

    private function getTotalResponses($filterSQL, $filterIDs): int
    {
        $sql = "SELECT COUNT(*) FROM responses r";
        if ($filterSQL) {
            $sql .= " WHERE $filterSQL";
        }

        return (int) DB::select($sql, $filterIDs)[0]->{'COUNT(*)'};
    }

    private function getResponsesWithBrandMention($brandId, $filterSQL, $filterIDs): int
    {
        $sql = "SELECT COUNT(DISTINCT r.id) FROM responses r 
                JOIN mentions m ON m.response_id = r.id 
                WHERE m.brand_id = ?";
        
        $params = [$brandId];
        if ($filterSQL) {
            $sql .= " AND $filterSQL";
            $params = array_merge($params, $filterIDs);
        }

        return (int) DB::select($sql, $params)[0]->{'COUNT(DISTINCT r.id)'};
    }

    private function getCoveredPrompts($filterSQL, $filterIDs): int
    {
        $sql = "SELECT COUNT(DISTINCT r.prompt_id) FROM responses r";
        if ($filterSQL) {
            $sql .= " WHERE $filterSQL";
        }

        return (int) DB::select($sql, $filterIDs)[0]->{'COUNT(DISTINCT r.prompt_id)'};
    }

    private function getTotalMentions($filterSQL, $filterIDs): int
    {
        $sql = "SELECT COUNT(*) FROM responses r JOIN mentions m ON m.response_id = r.id";
        if ($filterSQL) {
            $sql .= " WHERE $filterSQL";
        }

        return (int) DB::select($sql, $filterIDs)[0]->{'COUNT(*)'};
    }

    private function getBrandMentions($brandId, $filterSQL, $filterIDs): int
    {
        $sql = "SELECT COUNT(*) FROM responses r 
                JOIN mentions m ON m.response_id = r.id 
                WHERE m.brand_id = ?";
        
        $params = [$brandId];
        if ($filterSQL) {
            $sql .= " AND $filterSQL";
            $params = array_merge($params, $filterIDs);
        }

        return (int) DB::select($sql, $params)[0]->{'COUNT(*)'};
    }

    private function getSentiment($brandId, $filterSQL, $filterIDs): array
    {
        $sentiment = [
            'positive' => 0,
            'neutral' => 0,
            'negative' => 0,
            'total' => 0,
            'positive_share' => 0,
            'negative_share' => 0,
        ];

        $sql = "SELECT m.sentiment AS s, COUNT(*) AS cnt 
                FROM responses r JOIN mentions m ON m.response_id = r.id";
        
        $params = [];
        if ($brandId) {
            $sql .= " WHERE m.brand_id = ?";
            $params[] = $brandId;
            if ($filterSQL) {
                $sql .= " AND $filterSQL";
                $params = array_merge($params, $filterIDs);
            }
        } elseif ($filterSQL) {
            $sql .= " WHERE $filterSQL";
            $params = $filterIDs;
        }

        $sql .= " GROUP BY m.sentiment";

        $results = DB::select($sql, $params);

        foreach ($results as $row) {
            $s = strtolower($row->s ?? '');
            $c = (int) $row->cnt;
            if (isset($sentiment[$s])) {
                $sentiment[$s] = $c;
            }
        }

        $sentiment['total'] = $sentiment['positive'] + $sentiment['neutral'] + $sentiment['negative'];
        if ($sentiment['total'] > 0) {
            $sentiment['positive_share'] = $sentiment['positive'] / $sentiment['total'];
            $sentiment['negative_share'] = $sentiment['negative'] / $sentiment['total'];
        }

        return $sentiment;
    }

    private function getIntentMix($filterSQL, $filterIDs): array
    {
        $intentMix = ['informational' => 0, 'navigational' => 0, 'transactional' => 0, 'other' => 0];

        $sql = "SELECT r.intent AS i, COUNT(*) AS cnt FROM responses r";
        if ($filterSQL) {
            $sql .= " WHERE $filterSQL";
        }
        $sql .= " GROUP BY r.intent";

        $results = DB::select($sql, $filterIDs);

        foreach ($results as $row) {
            $k = strtolower($row->i ?? 'other');
            if (isset($intentMix[$k])) {
                $intentMix[$k] = (int) $row->cnt;
            }
        }

        return $intentMix;
    }

    private function getIntentByBrand($filterSQL, $filterIDs): array
    {
        $intentByBrand = [];

        $sql = "SELECT m.brand_id AS b, r.intent AS i, COUNT(*) AS cnt 
                FROM responses r JOIN mentions m ON m.response_id = r.id";
        if ($filterSQL) {
            $sql .= " WHERE $filterSQL";
        }
        $sql .= " GROUP BY m.brand_id, r.intent";

        $results = DB::select($sql, $filterIDs);

        foreach ($results as $row) {
            $b = (string) $row->b;
            $i = strtolower($row->i ?? 'other');
            $c = (int) $row->cnt;

            if (!isset($intentByBrand[$b])) {
                $intentByBrand[$b] = ['informational' => 0, 'navigational' => 0, 'transactional' => 0, 'other' => 0];
            }

            if (isset($intentByBrand[$b][$i])) {
                $intentByBrand[$b][$i] += $c;
            }
        }

        return $intentByBrand;
    }

    private function getCompetitiveShare($filterSQL, $filterIDs): array
    {
        $sql = "SELECT m.brand_id, COUNT(*) AS cnt 
                FROM responses r JOIN mentions m ON m.response_id = r.id";
        if ($filterSQL) {
            $sql .= " WHERE $filterSQL";
        }
        $sql .= " GROUP BY m.brand_id ORDER BY cnt DESC";

        $results = DB::select($sql, $filterIDs);

        $total = 0;
        $byBrand = [];

        foreach ($results as $row) {
            $cnt = (int) $row->cnt;
            $total += $cnt;
            $byBrand[$row->brand_id] = ['count' => $cnt, 'pct' => 0];
        }

        foreach ($byBrand as $bid => &$data) {
            $data['pct'] = $total > 0 ? ($data['count'] / $total) : 0;
        }

        return ['total' => $total, 'by_brand' => $byBrand];
    }

    private function getMentionsByBrand($filterSQL, $filterIDs): array
    {
        $sql = "SELECT m.brand_id, COUNT(*) AS cnt 
                FROM responses r JOIN mentions m ON m.response_id = r.id";
        if ($filterSQL) {
            $sql .= " WHERE $filterSQL";
        }
        $sql .= " GROUP BY m.brand_id ORDER BY cnt DESC";

        $results = DB::select($sql, $filterIDs);

        $output = [];
        foreach ($results as $row) {
            $output[$row->brand_id] = (int) $row->cnt;
        }

        return $output;
    }

    public function getMissedOpportunities($brandId, $filterSQL, $filterIDs): array
    {
        // If no brand or no filters, return empty
        if (!$brandId) {
            return [];
        }

        // Build the parameters array correctly
        $params = [];
        
        // For the EXISTS subquery
        if (!empty($filterIDs)) {
            $params = array_merge($params, $filterIDs);
        }
        
        // For the brand_id in NOT EXISTS
        $params[] = $brandId;
        
        // For the second filter in NOT EXISTS
        if (!empty($filterIDs)) {
            $params = array_merge($params, $filterIDs);
        }

        // Build subqueries
        $subHasResp = "SELECT 1 FROM responses r WHERE r.prompt_id = pr.id";
        if ($filterSQL) {
            $subHasResp .= " AND $filterSQL";
        }

        $subHasOurMention = "SELECT 1 FROM responses r 
                            JOIN mentions m ON m.response_id = r.id AND m.brand_id = ? 
                            WHERE r.prompt_id = pr.id";
        if ($filterSQL) {
            $subHasOurMention .= " AND $filterSQL";
        }

        $sql = "SELECT pr.id AS prompt_id, pr.category, pr.prompt, pr.search_volume
                FROM prompts pr
                WHERE pr.deleted_at IS NULL
                AND EXISTS ($subHasResp)
                AND NOT EXISTS ($subHasOurMention)
                ORDER BY pr.id DESC
                LIMIT 50";

        try {
            $results = DB::select($sql, $params);
        } catch (\Exception $e) {
            \Log::error('getMissedOpportunities error: ' . $e->getMessage());
            return [];
        }

        $missed = [];
        foreach ($results as $row) {
            $missed[] = [
                'category' => $row->category,
                'prompt' => $row->prompt,
                'search_volume' => $row->search_volume ?? 0,
                'intent' => 'other',
                'competitor_share' => 0,
                'competitor_positive_share' => 0,
                'missed_streak_3' => 0,
                'priority' => 50,
            ];
        }

        return $missed;
    }
}