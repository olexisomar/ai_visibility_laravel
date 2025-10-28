<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerformanceController extends Controller
{
    public function index(Request $request)
    {
        $action = $request->input('action', 'brand_mentions_overtime');

        return match($action) {
            'brand_mentions_overtime' => $this->brandMentionsOvertime($request),
            'citations_overtime' => $this->citationsOvertime($request),
            'intent_overtime' => $this->intentOvertime($request),
            'sentiment_overtime' => $this->sentimentOvertime($request),
            'persona_overtime' => $this->personaOvertime($request),
            'sentiment_sources' => $this->sentimentSources($request),
            'sentiment_explore' => $this->sentimentExplore($request),
            'market_share' => $this->marketShare($request),
            'market_share_trend' => $this->marketShareTrend($request),
            'market_share_table' => $this->marketShareTable($request),
            'market_share_table_citations' => $this->marketShareTableCitations($request),
            default => response()->json(['error' => 'Unknown action'], 400),
        };
    }

    private function buildFilters(Request $request): array
    {
        $params = [];
        $where = [];

        $from = $request->input('from');
        $to = $request->input('to');

        if (!$from || !$to) {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime('-21 days'));
        }

        $where[] = 'runs.started_at >= ?';  // ✅ CHANGED
        $params[] = $from;
        $where[] = 'runs.started_at < DATE_ADD(?, INTERVAL 1 DAY)';  // ✅ CHANGED
        $params[] = $to;

        $model = $request->input('model', 'all');
        if ($model === 'gpt') {
            $where[] = "runs.model LIKE 'gpt%'";
        } elseif ($model === 'google-ai-overview') {
            $where[] = "runs.model = 'google-ai-overview'";
        }

        // Topics filter
        $topics = $request->input('topic');
        if ($topics) {
            if (!is_array($topics)) {
                $topics = explode(',', $topics);
            }
            $placeholders = implode(',', array_fill(0, count($topics), '?'));
            $where[] = "COALESCE(r.prompt_category, pr.category) IN ($placeholders)";
            $params = array_merge($params, $topics);
        }

        // Intent filter
        $intent = $request->input('intent');
        if ($intent && $intent !== 'all') {
            $where[] = 'r.intent = ?';
            $params[] = $intent;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        return ['where' => $whereClause, 'params' => $params, 'from' => $from, 'to' => $to];
    }

    private function getBucketExpression(Request $request): string
    {
        $groupBy = strtolower($request->input('group_by', 'week'));
        
        if ($groupBy === 'day') {
            return "DATE(runs.started_at)";  // ✅ CHANGED
        }
        
        return "DATE_FORMAT(DATE_SUB(runs.started_at, INTERVAL (WEEKDAY(runs.started_at)) DAY), '%Y-%m-%d')";  // ✅ CHANGED
    }

    private function brandMentionsOvertime(Request $request)
    {
        $filters = $this->buildFilters($request);
        $bucketExpr = $this->getBucketExpression($request);
        $brands = $request->input('brand', []);
        
        if (is_string($brands)) {
            $brands = $brands ? [$brands] : [];
        }

        $joinScoped = "LEFT JOIN mentions m ON m.response_id = r.id";
        $joinAll = "LEFT JOIN mentions m_all ON m_all.response_id = r.id";

        if (!empty($brands)) {
            $placeholders = implode(',', array_fill(0, count($brands), '?'));
            $joinScoped = "LEFT JOIN mentions m ON m.response_id = r.id AND m.brand_id IN ($placeholders)";
            $filters['params'] = array_merge($filters['params'], $brands);
        }

        $sql = "SELECT
                {$bucketExpr} AS week_start,
                COUNT(DISTINCT r.id) AS total_responses,
                COUNT(DISTINCT CASE WHEN m.response_id IS NOT NULL THEN r.id END) AS mentioned,
                COUNT(DISTINCT CASE WHEN m.response_id IS NULL THEN r.id END) AS not_mentioned_scoped,
                COUNT(DISTINCT CASE WHEN m_all.response_id IS NULL THEN r.id END) AS no_brands
            FROM responses r
            JOIN runs ON runs.id = r.run_id
            LEFT JOIN prompts pr ON pr.id = r.prompt_id
            $joinScoped
            $joinAll
            {$filters['where']}
            GROUP BY week_start
            ORDER BY week_start ASC";

        $results = DB::select($sql, $filters['params']);

        $rows = [];
        foreach ($results as $row) {
            $rows[] = [
                'week_start' => $row->week_start,
                'mentioned' => (int) $row->mentioned,
                'not_mentioned' => (int) $row->not_mentioned_scoped,
                'no_brands' => empty($brands) ? (int) $row->no_brands : null,
                'total' => (int) $row->total_responses,
            ];
        }

        return response()->json(['rows' => $rows]);
    }

    private function citationsOvertime(Request $request)
    {
        $filters = $this->buildFilters($request);
        $bucketExpr = $this->getBucketExpression($request);

        $sql = "SELECT
                {$bucketExpr} AS week_start,
                COUNT(DISTINCT r.id) AS total_responses,
                COUNT(DISTINCT CASE WHEN rl.response_id IS NOT NULL THEN r.id END) AS cited
            FROM responses r
            JOIN runs ON runs.id = r.run_id
            LEFT JOIN prompts pr ON pr.id = r.prompt_id
            LEFT JOIN response_links rl ON rl.response_id = r.id
            {$filters['where']}
            GROUP BY week_start
            ORDER BY week_start ASC";

        $results = DB::select($sql, $filters['params']);

        $rows = [];
        foreach ($results as $row) {
            $total = (int) $row->total_responses;
            $cited = (int) $row->cited;
            $rows[] = [
                'week_start' => $row->week_start,
                'cited' => $cited,
                'not_cited' => max(0, $total - $cited),
                'total' => $total,
            ];
        }

        return response()->json(['rows' => $rows]);
    }

    private function intentOvertime(Request $request)
    {
        $filters = $this->buildFilters($request);
        $bucketExpr = $this->getBucketExpression($request);

        $sql = "SELECT
                {$bucketExpr} AS week_start,
                COUNT(DISTINCT CASE WHEN r.intent='informational' AND m.response_id IS NOT NULL THEN r.id END) AS informational,
                COUNT(DISTINCT CASE WHEN r.intent='navigational' AND m.response_id IS NOT NULL THEN r.id END) AS navigational,
                COUNT(DISTINCT CASE WHEN r.intent='transactional' AND m.response_id IS NOT NULL THEN r.id END) AS transactional,
                COUNT(DISTINCT CASE WHEN (r.intent IS NULL OR r.intent='' OR r.intent='other') AND m.response_id IS NOT NULL THEN r.id END) AS other_intent,
                COUNT(DISTINCT CASE WHEN m.response_id IS NOT NULL THEN r.id END) AS total_mentioned
            FROM responses r
            JOIN runs ON runs.id = r.run_id
            LEFT JOIN prompts pr ON pr.id = r.prompt_id
            LEFT JOIN mentions m ON m.response_id = r.id
            {$filters['where']}
            GROUP BY week_start
            ORDER BY week_start ASC";

        $results = DB::select($sql, $filters['params']);

        $rows = [];
        foreach ($results as $row) {
            $rows[] = [
                'week_start' => $row->week_start,
                'informational' => (int) $row->informational,
                'navigational' => (int) $row->navigational,
                'transactional' => (int) $row->transactional,
                'other' => (int) $row->other_intent,
                'total' => (int) $row->total_mentioned,
            ];
        }

        return response()->json(['rows' => $rows]);
    }

    private function sentimentOvertime(Request $request)
    {
        $filters = $this->buildFilters($request);
        $bucketExpr = $this->getBucketExpression($request);

        $sql = "SELECT
                {$bucketExpr} AS week_start,
                COUNT(m.id) AS total,
                SUM(CASE WHEN m.sentiment = 'positive' THEN 1 ELSE 0 END) AS positive,
                SUM(CASE WHEN m.sentiment = 'neutral' THEN 1 ELSE 0 END) AS neutral,
                SUM(CASE WHEN m.sentiment = 'negative' THEN 1 ELSE 0 END) AS negative
            FROM responses r
            JOIN runs ON runs.id = r.run_id
            LEFT JOIN prompts pr ON pr.id = r.prompt_id
            LEFT JOIN mentions m ON m.response_id = r.id
            {$filters['where']}
            GROUP BY week_start
            ORDER BY week_start ASC";

        $results = DB::select($sql, $filters['params']);

        $rows = [];
        foreach ($results as $row) {
            $rows[] = [
                'week_start' => $row->week_start,
                'positive' => (int) $row->positive,
                'neutral' => (int) $row->neutral,
                'negative' => (int) $row->negative,
                'total' => (int) $row->total,
            ];
        }

        return response()->json(['rows' => $rows]);
    }

    private function personaOvertime(Request $request)
    {
        $filters = $this->buildFilters($request);
        $bucketExpr = $this->getBucketExpression($request);

        $sql = "SELECT
                {$bucketExpr} AS week_start,
                COALESCE(p.name, '(unassigned)') AS persona,
                COUNT(*) AS cnt
            FROM responses r
            JOIN runs ON runs.id = r.run_id
            LEFT JOIN prompts pr ON pr.id = r.prompt_id
            LEFT JOIN personas p ON p.id = pr.persona_id
            {$filters['where']}
            GROUP BY week_start, persona
            ORDER BY week_start ASC";

        $results = DB::select($sql, $filters['params']);

        $rows = [];
        foreach ($results as $row) {
            $rows[] = [
                'week_start' => $row->week_start,
                'persona' => $row->persona,
                'count' => (int) $row->cnt,
            ];
        }

        return response()->json(['rows' => $rows]);
    }

    private function sentimentSources(Request $request)
    {
        $filters = $this->buildFilters($request);
        $polarity = $request->input('polarity', 'positive');
        $brand = $request->input('brand');
        $metric = $request->input('metric', 'citations'); // NEW: mentions or citations
        $ownedHost = $request->input('owned_host', '');

        // Get response IDs matching sentiment + brand filter
        $sql = "SELECT DISTINCT m.response_id
                FROM mentions m
                JOIN responses r ON r.id = m.response_id
                JOIN runs ON runs.id = r.run_id
                LEFT JOIN prompts pr ON pr.id = r.prompt_id
                {$filters['where']}
                AND LOWER(m.sentiment) = ?";

        $params = $filters['params'];
        $params[] = strtolower($polarity);

        if ($brand) {
            $sql .= " AND m.brand_id = ?";
            $params[] = $brand;
        }

        $responseIds = DB::select($sql, $params);
        $ids = array_column($responseIds, 'response_id');

        if (empty($ids)) {
            return response()->json([
                'total' => 0, 
                'rows' => [], 
                'owned_host' => $ownedHost, 
                'brand_id' => $brand, 
                'polarity' => $polarity,
                'metric' => $metric
            ]);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // METRIC: BY BRAND MENTIONS
        if ($metric === 'mentions') {
            // Count which brands were mentioned in these responses
            $mentionsSql = "SELECT 
                                m.brand_id AS domain,
                                COUNT(DISTINCT m.response_id) AS cnt
                            FROM mentions m
                            WHERE m.response_id IN ($placeholders)
                            AND LOWER(m.sentiment) = ?
                            GROUP BY m.brand_id
                            ORDER BY cnt DESC, domain ASC
                            LIMIT 50";
            
            $mentionsParams = array_merge($ids, [strtolower($polarity)]);
            $domains = DB::select($mentionsSql, $mentionsParams);

            $grandTotal = array_sum(array_column($domains, 'cnt'));

            $rows = [];
            foreach ($domains as $d) {
                $cnt = (int) $d->cnt;
                $rows[] = [
                    'domain' => $d->domain,
                    'count' => $cnt,
                    'pct' => $grandTotal ? round(100 * $cnt / $grandTotal, 2) : 0.0,
                ];
            }

            return response()->json([
                'total' => $grandTotal,
                'rows' => $rows,
                'owned_host' => $ownedHost,
                'brand_id' => $brand,
                'polarity' => $polarity,
                'metric' => $metric,
            ]);
        }

        // METRIC: BY WEBSITE CITATIONS (original logic)
        // Get total links count
        $totalLinksSql = "SELECT COUNT(*) AS total FROM (
                            SELECT DISTINCT response_id, 
                                LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(url), '://', -1), '/', 1)) AS dom
                            FROM response_links
                            WHERE response_id IN ($placeholders)
                        ) t";
        
        $totalLinks = (int) DB::select($totalLinksSql, $ids)[0]->total;

        // Get unsourced count (if owned host provided)
        $totalUnsourced = 0;
        if ($ownedHost) {
            $unsourcedSql = "SELECT COUNT(*) AS cnt FROM (
                                SELECT DISTINCT response_id
                                FROM (SELECT DISTINCT response_id FROM response_links WHERE response_id IN ($placeholders)) q
                                WHERE response_id NOT IN (
                                    SELECT DISTINCT response_id
                                    FROM response_links
                                    WHERE response_id IN ($placeholders)
                                    AND LOWER(
                                        CASE
                                            WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(url), '://', -1), '/', 1) LIKE 'www.%'
                                            THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(url), '://', -1), '/', 1), 5)
                                            ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(url), '://', -1), '/', 1)
                                        END
                                    ) = ?
                                )
                            ) x";
            
            $unsourcedParams = array_merge($ids, $ids, [strtolower($ownedHost)]);
            $totalUnsourced = (int) DB::select($unsourcedSql, $unsourcedParams)[0]->cnt;
        }

        $grandTotal = $totalLinks + $totalUnsourced;

        // Get domain histogram
        $domainSql = "SELECT
                        CASE WHEN dom LIKE 'www.%' THEN SUBSTRING(dom, 5) ELSE dom END AS domain,
                        COUNT(*) AS cnt
                    FROM (
                        SELECT DISTINCT
                            rl.response_id,
                            LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)) AS dom
                        FROM response_links rl
                        WHERE rl.response_id IN ($placeholders)
                    ) x
                    GROUP BY domain
                    ORDER BY cnt DESC, domain ASC
                    LIMIT 50";

        $domains = DB::select($domainSql, $ids);

        $rows = [];
        foreach ($domains as $d) {
            $cnt = (int) $d->cnt;
            $rows[] = [
                'domain' => $d->domain,
                'count' => $cnt,
                'pct' => $grandTotal ? round(100 * $cnt / $grandTotal, 2) : 0.0,
            ];
        }

        if ($ownedHost && $totalUnsourced > 0) {
            $rows[] = [
                'domain' => '(unsourced)',
                'count' => $totalUnsourced,
                'pct' => $grandTotal ? round(100 * $totalUnsourced / $grandTotal, 2) : 0.0,
            ];
        }

        // Sort by count desc
        usort($rows, function($a, $b) {
            if ($a['count'] === $b['count']) {
                return strcmp($a['domain'], $b['domain']);
            }
            return $b['count'] <=> $a['count'];
        });

        return response()->json([
            'total' => $grandTotal,
            'rows' => $rows,
            'owned_host' => $ownedHost,
            'brand_id' => $brand,
            'polarity' => $polarity,
            'metric' => $metric,
        ]);
    }

    private function sentimentExplore(Request $request)
    {
        $polarity = $request->input('polarity', 'positive');
        $brand = $request->input('brand');
        $domain = $request->input('domain');
        $ownedHost = $request->input('owned_host', '');
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(10, (int) $request->input('page_size', 20)));
        $offset = ($page - 1) * $pageSize;

        $filters = $this->buildFilters($request);

        // Base query for responses matching sentiment
        $baseSql = "SELECT DISTINCT m.response_id
                    FROM mentions m
                    JOIN responses r ON r.id = m.response_id
                    JOIN runs ON runs.id = r.run_id
                    LEFT JOIN prompts pr ON pr.id = r.prompt_id
                    {$filters['where']}
                    AND LOWER(m.sentiment) = ?";

        $baseParams = $filters['params'];
        $baseParams[] = strtolower($polarity);

        if ($brand) {
            $baseSql .= " AND m.brand_id = ?";
            $baseParams[] = $brand;
        }

        if ($domain === '(unsourced)' && $ownedHost) {
            // Get responses without owned host link
            $countSql = "SELECT COUNT(*) AS total FROM ($baseSql) q
                        WHERE NOT EXISTS (
                            SELECT 1
                            FROM response_links rl
                            WHERE rl.response_id = q.response_id
                            AND LOWER(
                                CASE
                                    WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                    THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                    ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                                END
                            ) = ?
                        )";

            $countParams = array_merge($baseParams, [strtolower($ownedHost)]);
            $total = (int) DB::select($countSql, $countParams)[0]->total;

            $rowsSql = "SELECT
                            r.raw_answer AS statement,
                            'Unsourced' AS source,
                            p.prompt AS prompt,
                            p.category AS topic,
                            runs.run_at AS run_at
                        FROM ($baseSql) q
                        JOIN responses r ON r.id = q.response_id
                        JOIN prompts p ON p.id = r.prompt_id
                        JOIN runs ON runs.id = r.run_id
                        WHERE NOT EXISTS (
                            SELECT 1
                            FROM response_links rl
                            WHERE rl.response_id = q.response_id
                            AND LOWER(
                                CASE
                                    WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                    THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                    ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                                END
                            ) = ?
                        )
                        ORDER BY runs.run_at DESC, r.id DESC
                        LIMIT $pageSize OFFSET $offset";

            $rowsParams = array_merge($baseParams, [strtolower($ownedHost)]);
            $rows = DB::select($rowsSql, $rowsParams);

        } elseif ($domain && $domain !== '(unsourced)') {
            // Specific domain
            $host = strtolower($domain);

            $countSql = "SELECT COUNT(*) FROM (
                            SELECT DISTINCT q.response_id
                            FROM ($baseSql) q
                            JOIN response_links rl ON rl.response_id = q.response_id
                            WHERE LOWER(
                                CASE
                                    WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                    THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                    ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                                END
                            ) = ?
                        ) x";

            $countParams = array_merge($baseParams, [$host]);
            \Log::info('Sentiment Explore Query', [
                'domain' => $domain,
                'countSql' => $countSql,
                'countParams' => $countParams,
            ]);
            $total = (int) DB::select($countSql, $countParams)[0]->{'COUNT(*)'};

            $rowsSql = "SELECT
                            r.raw_answer AS statement,
                            LOWER(CASE WHEN d.dom LIKE 'www.%' THEN SUBSTRING(d.dom, 5) ELSE d.dom END) AS source,
                            p.prompt AS prompt,
                            p.category AS topic,
                            runs.run_at AS run_at
                        FROM (
                            SELECT DISTINCT
                                q.response_id,
                                LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)) AS dom
                            FROM ($baseSql) q
                            JOIN response_links rl ON rl.response_id = q.response_id
                        ) d
                        JOIN responses r ON r.id = d.response_id
                        JOIN prompts p ON p.id = r.prompt_id
                        JOIN runs ON runs.id = r.run_id
                        WHERE (CASE WHEN d.dom LIKE 'www.%' THEN SUBSTRING(d.dom, 5) ELSE d.dom END) = ?
                        ORDER BY runs.run_at DESC, r.id DESC
                        LIMIT $pageSize OFFSET $offset";

            $rowsParams = array_merge($baseParams, [$host]);
            $rows = DB::select($rowsSql, $rowsParams);

        } else {
            // All sources - no domain filter
            // Each response can appear multiple times (one per source link)
            $countSql = "SELECT COUNT(*) FROM (
                            SELECT q.response_id, rl.url
                            FROM ($baseSql) q
                            LEFT JOIN response_links rl ON rl.response_id = q.response_id
                        ) x";

            $countParams = $baseParams;
            $total = (int) DB::select($countSql, $countParams)[0]->{'COUNT(*)'};

            $rowsSql = "SELECT
                            r.raw_answer AS statement,
                            COALESCE(
                                LOWER(
                                    CASE
                                        WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                        THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                        ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                                    END
                                ),
                                'Unsourced'
                            ) AS source,
                            p.prompt AS prompt,
                            p.category AS topic,
                            runs.run_at AS run_at
                        FROM ($baseSql) q
                        JOIN responses r ON r.id = q.response_id
                        JOIN prompts p ON p.id = r.prompt_id
                        JOIN runs ON runs.id = r.run_id
                        LEFT JOIN response_links rl ON rl.response_id = q.response_id
                        ORDER BY runs.run_at DESC, r.id DESC, rl.url
                        LIMIT $pageSize OFFSET $offset";

            $rowsParams = $baseParams;
            $rows = DB::select($rowsSql, $rowsParams);
        }

        // Extract keywords from statements
        $keywords = $this->extractKeywords(array_column($rows, 'statement'));

        return response()->json([
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'rows' => $rows,
            'keywords' => $keywords,
        ]);
    }

    private function extractKeywords(array $texts): array
    {
        $keywords = [];
        $stopwords = ['the', 'and', 'for', 'you', 'but', 'with', 'are', 'was', 'were', 'this', 'that', 'have', 'has', 'had', 'not', 'from', 'they', 'their', 'them', 'our', 'out', 'your', 'can', 'all', 'more', 'some', 'like'];

        foreach ($texts as $text) {
            $cleaned = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text));
            $words = preg_split('/\s+/', $cleaned);

            foreach ($words as $word) {
                if (strlen($word) < 3 || in_array($word, $stopwords)) {
                    continue;
                }
                $keywords[$word] = ($keywords[$word] ?? 0) + 1;
            }
        }

        arsort($keywords);
        
        $result = [];
        $i = 0;
        foreach ($keywords as $term => $count) {
            $result[] = ['term' => $term, 'count' => $count];
            if (++$i >= 30) break;
        }

        return $result;
    }

    private function marketShare(Request $request)
    {
        $filters = $this->buildFilters($request);
        $metric = $request->input('metric', 'mentions');

        if ($metric === 'mentions') {
            $sql = "SELECT
                    m.brand_id,
                    COALESCE(b.name, m.brand_id) AS brand_name,
                    COUNT(DISTINCT m.response_id) AS cnt
                FROM mentions m
                JOIN responses r ON r.id = m.response_id
                JOIN runs ON runs.id = r.run_id
                LEFT JOIN prompts pr ON pr.id = r.prompt_id
                LEFT JOIN brands b ON b.id = m.brand_id
                {$filters['where']}
                GROUP BY m.brand_id, brand_name
                ORDER BY cnt DESC
                LIMIT 200";
        } else {
            // Citations metric - domains cited in responses about a specific brand
            $brand = $request->input('brand');
            
            if ($brand) {
                // Get responses where this brand was mentioned
                $brandResponsesSql = "SELECT DISTINCT m.response_id
                                    FROM mentions m
                                    JOIN responses r ON r.id = m.response_id
                                    JOIN runs ON runs.id = r.run_id
                                    {$filters['where']}
                                    AND m.brand_id = ?";
                
                $brandParams = array_merge($filters['params'], [$brand]);
                $brandResponses = DB::select($brandResponsesSql, $brandParams);
                $responseIds = array_column($brandResponses, 'response_id');
                
                if (empty($responseIds)) {
                    return response()->json(['metric' => $metric, 'total' => 0, 'rows' => []]);
                }
                
                $placeholders = implode(',', array_fill(0, count($responseIds), '?'));
                
                // Get domains from ONLY these responses
                $sql = "SELECT
                            domain,
                            COUNT(DISTINCT response_id) AS cnt
                        FROM (
                            SELECT
                                DISTINCT rl.response_id,
                                LOWER(
                                    CASE
                                        WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                        THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                        ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                                    END
                                ) AS domain
                            FROM response_links rl
                            WHERE rl.response_id IN ($placeholders)
                        ) sub
                        GROUP BY domain
                        ORDER BY cnt DESC
                        LIMIT 200";
                
                $results = DB::select($sql, $responseIds);
                
            } else {
                // No brand filter - show all domains
                $sql = "SELECT
                        domain,
                        COUNT(DISTINCT response_id) AS cnt
                    FROM (
                        SELECT
                            DISTINCT rl.response_id,
                            LOWER(
                                CASE
                                    WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                    THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                    ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                                END
                            ) AS domain
                        FROM response_links rl
                        JOIN responses r ON r.id = rl.response_id
                        JOIN runs ON runs.id = r.run_id
                        {$filters['where']}
                    ) sub
                    GROUP BY domain
                    ORDER BY cnt DESC
                    LIMIT 200";
                
                $results = DB::select($sql, $filters['params']);
            }
            
            $total = array_sum(array_column($results, 'cnt'));

            // NO brand alias lookup - just use domains directly
            $rows = [];
            foreach ($results as $row) {
                $cnt = (int) $row->cnt;
                $rows[] = [
                    'brand_id' => $row->domain,
                    'brand_name' => $row->domain,
                    'count' => $cnt,
                    'pct' => $total ? round(100.0 * $cnt / $total, 2) : 0.0,
                ];
            }

            return response()->json(['metric' => $metric, 'total' => $total, 'rows' => $rows]);
        }

        $results = DB::select($sql, $filters['params']);
        $total = array_sum(array_column($results, 'cnt'));

        $rows = [];
        foreach ($results as $row) {
            $cnt = (int) $row->cnt;
            $rows[] = [
                'brand_id' => $row->brand_id,
                'brand_name' => $row->brand_name,
                'count' => $cnt,
                'pct' => $total ? round(100.0 * $cnt / $total, 2) : 0.0,
            ];
        }

        return response()->json(['metric' => $metric, 'total' => $total, 'rows' => $rows]);
    }

    private function marketShareTrend(Request $request)
    {
        $filters = $this->buildFilters($request);
        $bucketExpr = $this->getBucketExpression($request);
        $metric = $request->input('metric', 'mentions');

        $sql = "SELECT
                {$bucketExpr} AS week_start,
                m.brand_id,
                COALESCE(b.name, m.brand_id) AS brand_name,
                COUNT(DISTINCT m.response_id) AS cnt
            FROM mentions m
            JOIN responses r ON r.id = m.response_id
            JOIN runs ON runs.id = r.run_id
            LEFT JOIN prompts pr ON pr.id = r.prompt_id
            LEFT JOIN brands b ON b.id = m.brand_id
            {$filters['where']}
            GROUP BY week_start, m.brand_id, brand_name
            ORDER BY week_start ASC, cnt DESC";

        $results = DB::select($sql, $filters['params']);

        $rows = [];
        foreach ($results as $row) {
            $rows[] = [
                'week_start' => $row->week_start,
                'brand_id' => $row->brand_id,
                'brand_name' => $row->brand_name,
                'count' => (int) $row->cnt,
            ];
        }

        return response()->json(['metric' => $metric, 'rows' => $rows]);
    }

    private function marketShareTable(Request $request)
    {
        $filters = $this->buildFilters($request);
        $page = max(1, (int) $request->input('page', 1));
        $pageSize = min(100, max(10, (int) $request->input('page_size', 20)));
        $offset = ($page - 1) * $pageSize;
        $metric = $request->input('metric', 'mentions');

        // Main query: Get brand counts for current period
        $sql = "SELECT
                m.brand_id,
                COALESCE(b.name, m.brand_id) AS brand_name,
                COUNT(DISTINCT m.response_id) AS cnt,
                COUNT(DISTINCT r.prompt_id) AS prompts_count
            FROM mentions m
            JOIN responses r ON r.id = m.response_id
            JOIN runs ON runs.id = r.run_id
            LEFT JOIN prompts pr ON pr.id = r.prompt_id
            LEFT JOIN brands b ON b.id = m.brand_id
            {$filters['where']}
            GROUP BY m.brand_id, brand_name
            ORDER BY cnt DESC
            LIMIT $pageSize OFFSET $offset";

        $results = DB::select($sql, $filters['params']);

        // Total brands count
        $countSql = "SELECT COUNT(DISTINCT m.brand_id) AS total
                    FROM mentions m
                    JOIN responses r ON r.id = m.response_id
                    JOIN runs ON runs.id = r.run_id
                    LEFT JOIN prompts pr ON pr.id = r.prompt_id
                    {$filters['where']}";

        $totalBrands = (int) DB::select($countSql, $filters['params'])[0]->total;
        $total = array_sum(array_column($results, 'cnt'));

        // Get topics per brand
        $brandIds = array_column($results, 'brand_id');
        $topicsByBrand = [];
        
        if (!empty($brandIds)) {
            $placeholders = implode(',', array_fill(0, count($brandIds), '?'));
            $topicsSql = "SELECT 
                            m.brand_id,
                            pr.category AS topic,
                            COUNT(DISTINCT m.response_id) AS cnt
                        FROM mentions m
                        JOIN responses r ON r.id = m.response_id
                        JOIN runs ON runs.id = r.run_id
                        LEFT JOIN prompts pr ON pr.id = r.prompt_id
                        {$filters['where']}
                        AND m.brand_id IN ($placeholders)
                        AND pr.category IS NOT NULL
                        AND pr.category != ''
                        GROUP BY m.brand_id, pr.category
                        ORDER BY m.brand_id, cnt DESC";
            
            $topicsParams = array_merge($filters['params'], $brandIds);
            $topicsResults = DB::select($topicsSql, $topicsParams);
            
            foreach ($topicsResults as $tr) {
                if (!isset($topicsByBrand[$tr->brand_id])) {
                    $topicsByBrand[$tr->brand_id] = [];
                }
                $topicsByBrand[$tr->brand_id][] = $tr->topic;
            }
        }

        // Calculate previous period for comparison (same duration, shifted back)
        $from = $request->input('from');
        $to = $request->input('to');
        $prevFrom = null;
        $prevTo = null;
        $prevCounts = [];
        
        if ($from && $to) {
            try {
                $fromDate = new \DateTime($from);
                $toDate = new \DateTime($to);
                $interval = $fromDate->diff($toDate);
                
                $prevToDate = clone $fromDate;
                $prevToDate->modify('-1 day');
                $prevFromDate = clone $prevToDate;
                $prevFromDate->sub($interval);
                
                $prevFrom = $prevFromDate->format('Y-m-d');
                $prevTo = $prevToDate->format('Y-m-d');
                
                // Query previous period
                $prevFilters = $this->buildFiltersWithDates($request, $prevFrom, $prevTo);
                $prevSql = "SELECT
                            m.brand_id,
                            COUNT(DISTINCT m.response_id) AS cnt,
                            COUNT(DISTINCT r.prompt_id) AS prompts_count
                        FROM mentions m
                        JOIN responses r ON r.id = m.response_id
                        JOIN runs ON runs.id = r.run_id
                        LEFT JOIN prompts pr ON pr.id = r.prompt_id
                        {$prevFilters['where']}
                        GROUP BY m.brand_id";
                
                $prevResults = DB::select($prevSql, $prevFilters['params']);
                
                foreach ($prevResults as $pr) {
                    $prevCounts[$pr->brand_id] = [
                        'cnt' => (int) $pr->cnt,
                        'prompts' => (int) $pr->prompts_count
                    ];
                }
            } catch (\Exception $e) {
                // If date parsing fails, skip comparison
            }
        }

        // Build final rows
        $rows = [];
        foreach ($results as $i => $row) {
            $brandId = $row->brand_id;
            $cnt = (int) $row->cnt;
            $promptsCount = (int) $row->prompts_count;
            
            $pct = $total ? round(100.0 * $cnt / $total, 2) : 0.0;
            
            // Calculate changes
            $prevCnt = $prevCounts[$brandId]['cnt'] ?? 0;
            $prevPrompts = $prevCounts[$brandId]['prompts'] ?? 0;
            $prevTotal = array_sum(array_column($prevCounts, 'cnt')) ?: 1;
            $prevPct = $prevTotal ? round(100.0 * $prevCnt / $prevTotal, 2) : 0.0;
            
            $changePct = $pct - $prevPct;
            $changePrompts = $promptsCount - $prevPrompts;
            
            $rows[] = [
                'rank' => $offset + $i + 1,
                'brand_id' => $brandId,
                'brand_name' => $row->brand_name,
                'count' => $cnt,
                'pct' => $pct,
                'change_share' => $changePct, // Change in percentage points
                'delta_pct' => $changePct, // Alias for frontend compatibility
                'prompts_with_mentions' => $promptsCount,
                'prompts' => $promptsCount, // Alias
                'change_prompts' => $changePrompts,
                'topics' => $topicsByBrand[$brandId] ?? [],
                'mentioned_topics' => implode(', ', array_slice($topicsByBrand[$brandId] ?? [], 0, 5)), // String version
            ];
        }

        return response()->json([
            'metric' => $metric,
            'total_brands' => $totalBrands,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'rows' => $rows,
            'comparison_period' => $prevFrom && $prevTo ? "$prevFrom to $prevTo" : null,
        ]);
    }

    private function marketShareTableCitations(Request $request)
    {
        $filters = $this->buildFilters($request);
        $from = $request->input('from');
        $to = $request->input('to');

        // Get response IDs in current period
        $sql = "SELECT DISTINCT r.id AS response_id
                FROM responses r
                JOIN runs ON runs.id = r.run_id
                LEFT JOIN prompts pr ON pr.id = r.prompt_id
                {$filters['where']}";

        $responseIds = DB::select($sql, $filters['params']);
        $ids = array_column($responseIds, 'response_id');

        if (empty($ids)) {
            return response()->json([
                'total' => 0,
                'rows' => [],
            ]);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Get ALL domain citation counts for current period
        $sql = "SELECT
                    domain,
                    COUNT(DISTINCT response_id) AS cnt
                FROM (
                    SELECT DISTINCT
                        rl.response_id,
                        LOWER(
                            CASE
                                WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                            END
                        ) AS domain
                    FROM response_links rl
                    WHERE rl.response_id IN ($placeholders)
                ) sub
                GROUP BY domain
                ORDER BY cnt DESC";

        $currentResults = DB::select($sql, $ids);
        $totalCitations = array_sum(array_column($currentResults, 'cnt'));

        // Calculate previous period
        $prevCounts = [];
        $prevTotal = 0;

        if ($from && $to) {
            try {
                $fromDate = new \DateTime($from);
                $toDate = new \DateTime($to);
                $interval = $fromDate->diff($toDate);

                $prevToDate = clone $fromDate;
                $prevToDate->modify('-1 day');
                $prevFromDate = clone $prevToDate;
                $prevFromDate->sub($interval);

                $prevFrom = $prevFromDate->format('Y-m-d');
                $prevTo = $prevToDate->format('Y-m-d');

                // Build filters for previous period
                $prevWhere = ['WHERE 1=1'];
                $prevParams = [];

                $prevWhere[] = 'AND runs.started_at >= ?';
                $prevParams[] = $prevFrom;
                $prevWhere[] = 'AND runs.started_at < DATE_ADD(?, INTERVAL 1 DAY)';
                $prevParams[] = $prevTo;

                $model = $request->input('model');
                if ($model && $model !== 'all') {
                    $prevWhere[] = 'AND runs.model = ?';
                    $prevParams[] = $model;
                }

                $prevWhereStr = implode(' ', $prevWhere);

                // Get previous period response IDs
                $prevResponsesSql = "SELECT DISTINCT r.id AS response_id
                                    FROM responses r
                                    JOIN runs ON runs.id = r.run_id
                                    LEFT JOIN prompts pr ON pr.id = r.prompt_id
                                    $prevWhereStr";

                $prevResponseIds = DB::select($prevResponsesSql, $prevParams);
                $prevIds = array_column($prevResponseIds, 'response_id');

                if (!empty($prevIds)) {
                    $prevPlaceholders = implode(',', array_fill(0, count($prevIds), '?'));

                    $prevSql = "SELECT
                                    domain,
                                    COUNT(DISTINCT response_id) AS cnt
                                FROM (
                                    SELECT DISTINCT
                                        rl.response_id,
                                        LOWER(
                                            CASE
                                                WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                                THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                                ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                                            END
                                        ) AS domain
                                    FROM response_links rl
                                    WHERE rl.response_id IN ($prevPlaceholders)
                                ) sub
                                GROUP BY domain";

                    $prevResults = DB::select($prevSql, $prevIds);

                    foreach ($prevResults as $pr) {
                        $prevCounts[$pr->domain] = (int) $pr->cnt;
                    }

                    $prevTotal = array_sum($prevCounts) ?: 1;
                }
            } catch (\Exception $e) {
                // Skip comparison if date parsing fails
            }
        }

        // Get topics for ALL domains
        $domains = array_column($currentResults, 'domain');
        $topicsByDomain = [];

        if (!empty($domains)) {
            $domainPlaceholders = implode(',', array_fill(0, count($domains), '?'));
            
            $topicsSql = "SELECT
                            d.domain,
                            pr.category AS topic
                        FROM (
                            SELECT DISTINCT
                                rl.response_id,
                                LOWER(
                                    CASE
                                        WHEN SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1) LIKE 'www.%'
                                        THEN SUBSTRING(SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1), 5)
                                        ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(TRIM(rl.url), '://', -1), '/', 1)
                                    END
                                ) AS domain
                            FROM response_links rl
                            WHERE rl.response_id IN ($placeholders)
                        ) d
                        JOIN responses r ON r.id = d.response_id
                        LEFT JOIN prompts pr ON pr.id = r.prompt_id
                        WHERE d.domain IN ($domainPlaceholders)
                        AND pr.category IS NOT NULL
                        AND pr.category != ''
                        GROUP BY d.domain, pr.category";

            $topicsParams = array_merge($ids, $domains);
            $topicsResults = DB::select($topicsSql, $topicsParams);

            foreach ($topicsResults as $tr) {
                if (!isset($topicsByDomain[$tr->domain])) {
                    $topicsByDomain[$tr->domain] = [];
                }
                if (!in_array($tr->topic, $topicsByDomain[$tr->domain])) {
                    $topicsByDomain[$tr->domain][] = $tr->topic;
                }
            }
        }

        // Build ALL rows with comparison data
        $rows = [];
        foreach ($currentResults as $i => $row) {
            $domain = $row->domain;
            $cnt = (int) $row->cnt;
            $pct = $totalCitations ? round(100.0 * $cnt / $totalCitations, 2) : 0.0;

            $prevCnt = $prevCounts[$domain] ?? 0;
            $prevPct = $prevTotal ? round(100.0 * $prevCnt / $prevTotal, 2) : 0.0;
            $changePct = $pct - $prevPct;
            $changeCnt = $cnt - $prevCnt;

            $rows[] = [
                'rank' => $i + 1,
                'brand_id' => $domain,
                'brand_name' => $domain,
                'count' => $cnt,
                'pct' => $pct,
                'change_share' => $changePct,
                'citations' => $cnt,
                'change_citations' => $changeCnt,
                'topics' => $topicsByDomain[$domain] ?? [],
                'mentioned_topics' => implode(', ', array_slice($topicsByDomain[$domain] ?? [], 0, 5)),
            ];
        }

        return response()->json([
            'total' => $totalCitations,
            'rows' => $rows,
            'comparison_period' => isset($prevFrom) && isset($prevTo) ? "$prevFrom to $prevTo" : null,
        ]);
    }

    // Build filters with optional custom date range for comparison periods
    private function buildFiltersWithDates(Request $request, $customFrom = null, $customTo = null)
    {
        $where = "WHERE 1=1";
        $params = [];

        $from = $customFrom ?? $request->input('from');
        $to = $customTo ?? $request->input('to');
        $model = $request->input('model');

        if ($from) {
            $where .= " AND DATE(runs.run_at) >= ?";
            $params[] = $from;
        }
        if ($to) {
            $where .= " AND DATE(runs.run_at) <= ?";
            $params[] = $to;
        }
        if ($model && $model !== 'all') {
            $where .= " AND runs.model = ?";
            $params[] = $model;
        }

        return ['where' => $where, 'params' => $params];
    }
}