<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RawSuggestion;
use App\Models\Prompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuggestionController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'new');
        $minScore = $request->input('min_score');
        $source = $request->input('source');
        $lang = $request->input('lang');
        $geo = $request->input('geo');
        $seed = $request->input('seed');
        $page = max(1, (int)$request->input('page', 1));
        $pageSize = min(200, max(1, (int)$request->input('page_size', 50)));
        $sort = $request->input('sort', 'score');

        $query = RawSuggestion::query();

        if ($status) {
            $query->where('status', $status);
        }

        if ($minScore !== null && $minScore !== '') {
            $query->where('score_auto', '>=', (float)$minScore);
        }

        if ($source) {
            $query->where('source', $source);
        }

        if ($lang) {
            $query->where('lang', $lang);
        }

        if ($geo) {
            $query->where('geo', $geo);
        }

        if ($seed) {
            $query->where('seed_term', 'like', "%{$seed}%");
        }

        $total = $query->count();

        if ($sort === 'recent') {
            $query->orderBy('collected_at', 'desc')->orderBy('id', 'desc');
        } else {
            $query->orderBy('score_auto', 'desc')->orderBy('collected_at', 'desc');
        }

        $rows = $query->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        return response()->json([
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'has_next' => (($page - 1) * $pageSize + $rows->count()) < $total,
            'sort' => $sort,
            'filters' => compact('status', 'minScore', 'source', 'lang', 'geo', 'seed'),
            'rows' => $rows,
        ]);
    }

    public function approve($id, Request $request)
    {
        $makeActive = $request->boolean('make_active');

        DB::beginTransaction();
        try {
            $suggestion = RawSuggestion::findOrFail($id);

            if (empty($suggestion->category)) {
                return response()->json(['error' => 'Topic is required to approve a suggestion'], 400);
            }

            $normalized = $suggestion->normalized ?: $this->normalizeQuery($suggestion->text);
            $hashNorm = $suggestion->hash_norm ?: sha1($normalized);

            $status = $makeActive ? 'active' : 'approved';

            $prompt = Prompt::updateOrCreate(
                ['hash_norm' => $hashNorm],
                [
                    'category' => $suggestion->category,
                    'prompt' => $normalized,
                    'persona_id' => $suggestion->persona_id,
                    'source' => $suggestion->source,
                    'lang' => $suggestion->lang,
                    'geo' => $suggestion->geo,
                    'first_seen' => $suggestion->collected_at,
                    'last_seen' => $suggestion->collected_at,
                    'status' => $status,
                    'serp_features' => $suggestion->serp_features,
                    'search_volume' => $suggestion->search_volume,
                    'score_auto' => $suggestion->score_auto,
                    'topic_cluster' => $suggestion->topic_cluster,
                    'notes' => "seed={$suggestion->seed_term} | source={$suggestion->source}",
                    'created_by' => 'generated',
                ]
            );

            $suggestion->update([
                'status' => 'approved',
                'approved_at' => now(),
                'prompt_id' => $prompt->id,
            ]);

            DB::commit();

            return response()->json([
                'ok' => true,
                'prompt_id' => $prompt->id,
                'hash_norm' => $hashNorm,
                'normalized' => $normalized,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function reject($id)
    {
        $suggestion = RawSuggestion::findOrFail($id);
        $suggestion->update([
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id, 'status' => 'rejected']);
    }

    public function bulkApprove(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $suggestions = RawSuggestion::whereIn('id', $validated['ids'])->get();

        DB::beginTransaction();
        try {
            $approved = 0;
            $inserted = 0;

            foreach ($suggestions as $suggestion) {
                if (empty($suggestion->category)) continue;

                $normalized = $suggestion->normalized ?: $this->normalizeQuery($suggestion->text);
                $hashNorm = $suggestion->hash_norm ?: sha1($normalized);

                $prompt = Prompt::updateOrCreate(
                    ['hash_norm' => $hashNorm],
                    [
                        'category' => $suggestion->category,
                        'prompt' => $normalized,
                        'persona_id' => $suggestion->persona_id,
                        'source' => $suggestion->source,
                        'lang' => $suggestion->lang,
                        'geo' => $suggestion->geo,
                        'status' => 'approved',
                        'created_by' => 'generated',
                    ]
                );

                $suggestion->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'prompt_id' => $prompt->id,
                ]);

                if ($prompt->wasRecentlyCreated) {
                    $inserted++;
                }
                $approved++;
            }

            DB::commit();

            return response()->json([
                'ok' => true,
                'approved_suggestions' => $approved,
                'inserted_prompts' => $inserted,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function bulkReject(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        RawSuggestion::whereIn('id', $validated['ids'])
            ->update(['status' => 'rejected', 'rejected_at' => now()]);

        return response()->json(['ok' => true, 'count' => count($validated['ids'])]);
    }

    public function count()
    {
        $pending = RawSuggestion::where('status', 'new')->count();

        return response()->json(['pending' => $pending]);
    }

    private function normalizeQuery(string $text): string
    {
        $text = strtolower(trim($text));
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text, " \t\n\r\0\x0B.,;:!?'\"()[]{}");
        return $text;
    }
}