<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prompt;
use App\Models\Response;
use App\Models\Mention;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromptController extends Controller
{
    public function index(Request $request)
    {
        $prompts = Prompt::whereNull('deleted_at')->orderBy('id')->get();

        return response()->json($prompts);
    }

    public function status(Request $request)
    {
        $scope = $request->input('scope', 'latest');

        if ($scope === 'latest') {
            $latestRunId = DB::table('runs')->max('id');
            
            $rows = DB::table('prompts as p')
                ->leftJoin('responses as r', function($join) use ($latestRunId) {
                    $join->on('r.prompt_id', '=', 'p.id')
                         ->where('r.run_id', '=', $latestRunId);
                })
                ->leftJoin('mentions as m', 'm.response_id', '=', 'r.id')
                ->select(
                    'p.id',
                    'p.category',
                    'p.prompt',
                    'p.search_volume',
                    'p.is_paused',
                    'r.id as response_id',
                    DB::raw('CASE WHEN COUNT(m.id) > 0 THEN 1 ELSE 0 END as mentioned'),
                    DB::raw('COUNT(m.id) as mentions_count'),
                    DB::raw('COUNT(DISTINCT m.brand_id) as brands_count')
                )
                ->whereNull('p.deleted_at')
                ->groupBy('p.id', 'p.category', 'p.prompt', 'p.search_volume', 'p.is_paused', 'r.id')
                ->get();
        } else {
            $rows = DB::table('prompts as p')
                ->leftJoin('responses as r', 'r.prompt_id', '=', 'p.id')
                ->leftJoin('mentions as m', 'm.response_id', '=', 'r.id')
                ->select(
                    'p.id',
                    'p.category',
                    'p.prompt',
                    'p.search_volume',
                    'p.is_paused',
                    DB::raw('NULL as response_id'),
                    DB::raw('CASE WHEN COUNT(m.id) > 0 THEN 1 ELSE 0 END as mentioned'),
                    DB::raw('COUNT(m.id) as mentions_count'),
                    DB::raw('COUNT(DISTINCT m.brand_id) as brands_count')
                )
                ->whereNull('p.deleted_at')
                ->groupBy('p.id', 'p.category', 'p.prompt', 'p.search_volume', 'p.is_paused')
                ->get();
        }

        return response()->json(['rows' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $request->all();
        $id = isset($data['id']) ? (int)$data['id'] : null;
        
        $validated = $request->validate([
            'category' => 'nullable|string|max:100',
            'prompt' => 'required|string',
            'source' => 'nullable|string|max:32',
            'search_volume' => 'nullable|integer',
            'is_paused' => 'nullable|boolean',
            'persona_id' => 'nullable|integer',
        ]);

        try {
            if ($id) {
                // Update existing
                $prompt = DB::table('prompts')->where('id', $id)->first();
                
                if (!$prompt) {
                    return response()->json(['error' => 'Prompt not found'], 404);
                }
                
                $updateData = [
                    'category' => $validated['category'] ?? $prompt->category,
                    'prompt' => $validated['prompt'],
                    'search_volume' => $validated['search_volume'] ?? null,
                    'persona_id' => $validated['persona_id'] ?? $prompt->persona_id,
                    'updated_at' => now(),
                ];
                
                // Only update source if explicitly provided (preserve existing if not)
                if (isset($validated['source']) && $validated['source'] !== '') {
                    $updateData['source'] = $validated['source'];
                }
                
                // Only update is_paused if explicitly provided
                if (isset($validated['is_paused'])) {
                    $updateData['is_paused'] = $validated['is_paused'] ? 1 : 0;
                }
                
                DB::table('prompts')->where('id', $id)->update($updateData);
                
                return response()->json(['ok' => true, 'id' => $id]);
            } else {
                // Insert new - automatically set source to 'manual' if not provided
                $insertData = [
                    'category' => $validated['category'] ?? null,
                    'prompt' => $validated['prompt'],
                    'source' => $validated['source'] ?? 'Manually Added', // ← AUTO-SET SOURCE
                    'search_volume' => $validated['search_volume'] ?? null,
                    'persona_id' => $validated['persona_id'] ?? null,
                    'is_paused' => 0,
                    'status' => 'active',
                    'created_by' => 'manual',
                    'updated_at' => now(),
                ];
                
                $newId = DB::table('prompts')->insertGetId($insertData);
                
                return response()->json(['ok' => true, 'id' => $newId]);
            }
        } catch (\Exception $e) {
            Log::error('Prompt store error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $updated = DB::table('prompts')
                ->where('id', $id)
                ->update(['deleted_at' => now()]);
            
            if ($updated === 0) {
                return response()->json(['error' => 'Prompt not found'], 404);
            }
            
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Prompt delete error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function togglePause(Request $request, $id)
    {
        $isPaused = $request->input('is_paused', 1);
        
        try {
            $updated = DB::table('prompts')
                ->where('id', $id)
                ->update([
                    'is_paused' => $isPaused ? 1 : 0,
                    'updated_at' => now(),
                ]);
            
            if ($updated === 0) {
                return response()->json(['error' => 'Prompt not found'], 404);
            }
            
            return response()->json([
                'ok' => true,
                'id' => $id,
                'is_paused' => $isPaused,
            ]);
        } catch (\Exception $e) {
            Log::error('Prompt toggle pause error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function bulkPause(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $updated = Prompt::whereIn('id', $validated['ids'])
            ->update(['is_paused' => true, 'updated_at' => now()]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    public function bulkResume(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $updated = Prompt::whereIn('id', $validated['ids'])
            ->update(['is_paused' => false, 'updated_at' => now()]);

        return response()->json(['ok' => true, 'updated' => $updated]);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $updated = Prompt::whereIn('id', $validated['ids'])
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true, 'deleted' => $updated]);
    }

    public function export(Request $request)
    {
        $scope = $request->input('scope', 'latest');
        
        try {
            if ($scope === 'latest') {
                // Latest run only
                $latestRunId = DB::table('runs')->max('id');
                
                if (!$latestRunId) {
                    return response()->json(['error' => 'No runs found'], 404);
                }
                
                $prompts = DB::table('prompts')
                    ->leftJoin('responses', 'prompts.id', '=', 'responses.prompt_id')
                    ->leftJoin('mentions', 'responses.id', '=', 'mentions.response_id')
                    ->where('responses.run_id', $latestRunId)
                    ->whereNull('prompts.deleted_at')
                    ->select([
                        'prompts.id',
                        'prompts.category',
                        'prompts.prompt',
                        'prompts.source',
                        'prompts.search_volume',
                        'prompts.persona_id',
                        'prompts.is_paused',
                        DB::raw('COUNT(DISTINCT mentions.id) as mention_count')
                    ])
                    ->groupBy('prompts.id', 'prompts.category', 'prompts.prompt', 'prompts.source', 
                            'prompts.search_volume', 'prompts.persona_id', 'prompts.is_paused')
                    ->get();
            } else {
                // All prompts
                $prompts = DB::table('prompts')
                    ->whereNull('deleted_at')
                    ->select([
                        'id',
                        'category',
                        'prompt',
                        'source',
                        'search_volume',
                        'persona_id',
                        'is_paused'
                    ])
                    ->get();
            }

            // Build CSV
            $csv = "id,category,prompt,search_volume,persona_id,source,paused\n";
            
            foreach ($prompts as $p) {
                $csv .= sprintf(
                    "%d,%s,%s,%s,%s,%s,%d\n",
                    $p->id,
                    $this->escapeCsv($p->category ?? ''),
                    $this->escapeCsv($p->prompt ?? ''),
                    $p->search_volume ?? '',
                    $p->persona_id ?? '',
                    $this->escapeCsv($p->source ?? ''),
                    $p->is_paused ?? 0
                );
            }

            return response($csv, 200)
                ->header('Content-Type', 'text/csv; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="prompts_export_' . date('Y-m-d') . '.csv"');

        } catch (\Exception $e) {
            Log::error('CSV export error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Escape CSV field
     */
    private function escapeCsv(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        
        // If contains comma, quote, or newline, wrap in quotes and escape quotes
        if (preg_match('/[",\n\r]/', $value)) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        
        return $value;
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'replace' => 'nullable|boolean',
        ]);

        try {
            $replace = $request->input('replace', false);
            $file = $request->file('file');
            
            // Read CSV
            $handle = fopen($file->getRealPath(), 'r');
            if (!$handle) {
                return response()->json(['error' => 'Could not open file'], 400);
            }

            // Get headers
            $headers = fgetcsv($handle);
            if (!$headers) {
                fclose($handle);
                return response()->json(['error' => 'CSV is empty or invalid'], 400);
            }

            // Normalize headers (trim, lowercase)
            $headers = array_map(fn($h) => trim(strtolower($h)), $headers);

            // Required fields
            $requiredFields = ['prompt'];
            foreach ($requiredFields as $field) {
                if (!in_array($field, $headers)) {
                    fclose($handle);
                    return response()->json(['error' => "Missing required column: $field"], 400);
                }
            }

            // Optional: Replace all prompts
            if ($replace) {
                DB::table('prompts')->update(['deleted_at' => now()]);
            }

            $imported = 0;
            $skipped = 0;
            $errors = [];

            DB::beginTransaction();

            while (($row = fgetcsv($handle)) !== false) {
                // Skip empty rows
                if (count(array_filter($row)) === 0) {
                    continue;
                }

                // Map row to associative array
                $data = array_combine($headers, $row);

                // Extract fields
                $prompt = trim($data['prompt'] ?? '');
                if ($prompt === '') {
                    $skipped++;
                    continue;
                }

                $category = trim($data['category'] ?? '');
                $sourceRaw = isset($data['source']) ? trim($data['source']) : '';
                $source = ($sourceRaw !== '') ? $sourceRaw : 'csv-import';
                $searchVolume = isset($data['search_volume']) && $data['search_volume'] !== '' 
                    ? (int)$data['search_volume'] 
                    : null;
                $personaId = isset($data['persona_id']) && $data['persona_id'] !== '' 
                    ? (int)$data['persona_id'] 
                    : null;
                $isPaused = isset($data['paused']) && in_array(strtolower($data['paused']), ['1', 'yes', 'true']) 
                    ? 1 
                    : 0;

                try {
                    // Check if ID provided for update
                    $id = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : null;

                    if ($id && $id > 0) {
                        // Update existing
                        $exists = DB::table('prompts')->where('id', $id)->exists();
                        if ($exists) {
                            DB::table('prompts')->where('id', $id)->update([
                                'category' => $category ?: null,
                                'prompt' => $prompt,
                                'source' => $source,
                                'search_volume' => $searchVolume,
                                'persona_id' => $personaId,
                                'is_paused' => $isPaused,
                                'updated_at' => now(),
                            ]);
                            $imported++;
                        } else {
                            $skipped++;
                            $errors[] = "ID $id not found, skipped";
                        }
                    } else {
                        // Insert new
                        DB::table('prompts')->insert([
                            'category' => $category ?: null,
                            'prompt' => $prompt,
                            'source' => $source,
                            'search_volume' => $searchVolume,
                            'persona_id' => $personaId,
                            'is_paused' => $isPaused,
                            'status' => 'active',
                            'created_by' => 'csv-import',
                            'updated_at' => now(),
                        ]);
                        $imported++;
                    }
                } catch (\Exception $e) {
                    $skipped++;
                    $errors[] = substr($e->getMessage(), 0, 100);
                }
            }

            fclose($handle);
            DB::commit();

            return response()->json([
                'ok' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => array_slice($errors, 0, 10), // Return first 10 errors
            ]);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('CSV import error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function handleAdminAction(Request $request)
    {
        $action = $request->input('action');
        
        return match($action) {
            'list_prompts' => $this->index($request),
            'prompts_status' => $this->status($request),
            'save_prompt' => $this->store($request),
            'delete_prompt' => $this->destroy($request->input('id')),
            'toggle_pause_prompt' => $this->togglePause($request, $request->input('id')),
            'bulk_pause_prompts' => $this->bulkPause($request),
            'bulk_resume_prompts' => $this->bulkResume($request),
            'bulk_delete_prompts' => $this->bulkDelete($request),
            default => response()->json(['error' => 'Unknown action: ' . $action], 400),
        };
    }
}