<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\BrandAlias;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandController extends Controller
{
    public function index()
    {
        $brands = Brand::with('aliases')->orderBy('name')->get();
        $primaryBrandId = Setting::get('primary_brand_id');

        // Format brands for frontend
        $formattedBrands = $brands->map(function ($brand) {
            return [
                'id' => $brand->id,
                'name' => $brand->name,
                'aliases' => $brand->aliases->pluck('alias')->toArray(), // ← Convert to array of strings
            ];
        });

        return response()->json([
            'brands' => $formattedBrands,
            'primary_brand_id' => $primaryBrandId,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'aliases' => 'nullable|array',
            'aliases.*' => 'string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $brand = Brand::updateOrCreate(
                ['id' => $validated['id']],
                ['name' => $validated['name']]
            );

            // Replace aliases
            BrandAlias::where('brand_id', $brand->id)->delete();
            
            if (!empty($validated['aliases'])) {
                foreach ($validated['aliases'] as $alias) {
                    if (trim($alias)) {
                        BrandAlias::create([
                            'brand_id' => $brand->id,
                            'alias' => trim($alias),
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            // Normalize brand ID
            $id = $this->normalizeBrandId($id);
            
            // Delete aliases first
            DB::table('brand_aliases')->where('brand_id', $id)->delete();
            
            // Delete brand
            $deleted = DB::table('brands')->where('id', $id)->delete();
            
            if ($deleted === 0) {
                return response()->json(['error' => 'Brand not found'], 404);
            }
            
            // Clear primary setting if this was the primary brand
            DB::table('settings')
                ->where('key', 'primary_brand_id')
                ->where('value', $id)
                ->delete();
            
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Brand delete error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function setPrimary(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|string',
        ]);
        
        try {
            $id = $this->normalizeBrandId($validated['id']);
            
            // Verify brand exists
            $exists = DB::table('brands')->where('id', $id)->exists();
            
            if (!$exists) {
                return response()->json(['error' => 'Brand not found'], 404);
            }
            
            // Set as primary
            DB::table('settings')->updateOrInsert(
                ['key' => 'primary_brand_id'],
                ['value' => $id]
            );
            
            return response()->json(['ok' => true, 'id' => $id]);
        } catch (\Exception $e) {
            Log::error('Set primary brand error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $brands = DB::table('brands')
                ->orderBy('name')
                ->get();
            
            // Get all aliases grouped by brand_id
            $aliasesRaw = DB::table('brand_aliases')
                ->orderBy('brand_id')
                ->orderBy('alias')
                ->get();
            
            $aliasesByBrand = [];
            foreach ($aliasesRaw as $alias) {
                $aliasesByBrand[$alias->brand_id][] = $alias->alias;
            }
            
            // Build CSV
            $csv = "id,name,aliases\n";
            
            foreach ($brands as $brand) {
                $aliases = $aliasesByBrand[$brand->id] ?? [];
                $aliasesStr = implode(', ', $aliases);
                
                $csv .= sprintf(
                    "%s,%s,%s\n",
                    $this->escapeCsv($brand->id),
                    $this->escapeCsv($brand->name),
                    $this->escapeCsv($aliasesStr)
                );
            }
            
            return response($csv, 200)
                ->header('Content-Type', 'text/csv; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="brands_export_' . date('Y-m-d') . '.csv"');
            
        } catch (\Exception $e) {
            Log::error('Brands CSV export error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'replace' => 'nullable|boolean',
            'primary' => 'nullable|string',
        ]);
        
        try {
            $replace = $request->input('replace', false);
            $primary = $request->input('primary', null);
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
            
            // Normalize headers
            $headers = array_map(fn($h) => trim(strtolower($h)), $headers);
            
            // Required fields
            $requiredFields = ['id', 'name'];
            foreach ($requiredFields as $field) {
                if (!in_array($field, $headers)) {
                    fclose($handle);
                    return response()->json(['error' => "Missing required column: $field"], 400);
                }
            }
            
            // Optional: Replace all brands
            if ($replace) {
                DB::table('brand_aliases')->truncate();
                DB::table('brands')->truncate();
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
                $id = trim($data['id'] ?? '');
                $name = trim($data['name'] ?? '');
                
                if ($id === '' || $name === '') {
                    $skipped++;
                    continue;
                }
                
                // Normalize brand ID
                try {
                    $id = $this->normalizeBrandId($id);
                } catch (\Exception $e) {
                    $skipped++;
                    $errors[] = substr($e->getMessage(), 0, 100);
                    continue;
                }
                
                // Parse aliases (comma-separated)
                $aliasesRaw = trim($data['aliases'] ?? '');
                $aliases = [];
                if ($aliasesRaw !== '') {
                    $aliases = array_filter(array_map('trim', explode(',', $aliasesRaw)));
                }
                
                try {
                    // Upsert brand
                    DB::table('brands')->updateOrInsert(
                        ['id' => $id],
                        ['name' => $name]
                    );
                    
                    // Delete old aliases
                    DB::table('brand_aliases')->where('brand_id', $id)->delete();
                    
                    // Insert new aliases
                    foreach ($aliases as $alias) {
                        DB::table('brand_aliases')->insert([
                            'brand_id' => $id,
                            'alias' => $alias,
                        ]);
                    }
                    
                    $imported++;
                } catch (\Exception $e) {
                    $skipped++;
                    $errors[] = substr($e->getMessage(), 0, 100);
                }
            }
            
            fclose($handle);
            
            // Set primary brand if specified
            if ($primary && $primary !== '') {
                try {
                    $primary = $this->normalizeBrandId($primary);
                    $exists = DB::table('brands')->where('id', $primary)->exists();
                    if ($exists) {
                        DB::table('settings')->updateOrInsert(
                            ['key' => 'primary_brand_id'],
                            ['value' => $primary]
                        );
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to set primary brand: ' . $e->getMessage());
                }
            }
            
            DB::commit();
            
            return response()->json([
                'ok' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'primary' => $primary,
                'errors' => array_slice($errors, 0, 10),
            ]);
            
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error('Brands CSV import error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function normalizeBrandId(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            throw new \RuntimeException("Brand id is empty");
        }
        
        // If a URL, parse
        if (preg_match('~^https?://~i', $s)) {
            $u = parse_url($s);
            if (!$u || empty($u['host'])) {
                throw new \RuntimeException("Invalid URL '$raw'");
            }
            $host = strtolower(preg_replace('/^www\./i', '', $u['host']));
            $path = ltrim((string)($u['path'] ?? ''), '/');
        } else {
            $s = preg_replace('/[?#].*$/', '', $s);
            $s = rtrim($s, "/ \t\r\n\0\x0B");
            $parts = explode('/', $s, 2);
            $host = strtolower(preg_replace('/^www\./i', '', trim($parts[0])));
            $path = isset($parts[1]) ? ltrim(trim($parts[1]), '/') : '';
        }
        
        // Validate host
        $hostRegex = '/^(?!.*\.\.)(?!.*\.$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/';
        if ($host === '' || !preg_match($hostRegex, $host)) {
            throw new \RuntimeException("Invalid brand host '$host'");
        }
        
        // Validate path
        if ($path !== '') {
            $pathRegex = '/^(?:[A-Za-z0-9._~\-]+(?:\/[A-Za-z0-9._~\-]+)*)?$/';
            if (!preg_match($pathRegex, $path)) {
                throw new \RuntimeException("Invalid path in brand id '$path'");
            }
        }
        
        $id = $host . ($path !== '' ? ('/' . $path) : '');
        
        if (strlen($id) > 191) {
            throw new \RuntimeException("Brand id too long (max 191 chars)");
        }
        
        return $id;
    }

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
        public function handleAction(Request $request)
    {
        $action = $request->input('action');
        
        return match($action) {
            'list_brands' => $this->index($request),
            'save_brand' => $this->store($request),
            'delete_brand' => $this->destroy($request->input('id')),
            'set_primary_brand' => $this->setPrimary($request),
            default => $this->index($request),
        };
    }
}