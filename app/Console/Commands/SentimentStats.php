<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SentimentStats extends Command
{
    protected $signature = 'sentiment:stats {--month= : Month to analyze (YYYY-MM)}';
    protected $description = 'Show sentiment analysis statistics and cost estimates';

    public function handle()
    {
        $month = $this->option('month') 
            ? \Carbon\Carbon::parse($this->option('month'))->startOfMonth()
            : now()->startOfMonth();
        
        $monthEnd = $month->copy()->endOfMonth();
        
        $this->info("📊 Sentiment Analysis Stats for " . $month->format('F Y'));
        $this->line('');
        
        // Get mention stats - JOIN with responses to get created_at
        $stats = DB::table('mentions as m')
            ->join('responses as r', 'm.response_id', '=', 'r.id')
            ->whereBetween('r.created_at', [$month, $monthEnd])
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN m.sentiment = "positive" THEN 1 ELSE 0 END) as positive,
                SUM(CASE WHEN m.sentiment = "negative" THEN 1 ELSE 0 END) as negative,
                SUM(CASE WHEN m.sentiment = "neutral" THEN 1 ELSE 0 END) as neutral
            ')
            ->first();
        
        if ($stats->total == 0) {
            $this->warn('No mentions found for this period.');
            return 0;
        }
        
        // Display stats
        $this->table(
            ['Metric', 'Count', 'Percentage'],
            [
                ['Total Mentions', $stats->total, '100%'],
                ['Positive', $stats->positive, round(($stats->positive / $stats->total) * 100, 1) . '%'],
                ['Negative', $stats->negative, round(($stats->negative / $stats->total) * 100, 1) . '%'],
                ['Neutral', $stats->neutral, round(($stats->neutral / $stats->total) * 100, 1) . '%'],
            ]
        );
        
        $this->line('');
        
        // Cost estimate
        $avgTokens = 210; // ~200 input + 10 output
        $costPerToken = 0.00000015; // GPT-4o-mini rate
        $estimatedCost = $stats->total * $avgTokens * $costPerToken;
        
        $this->info("💰 Estimated AI Sentiment Cost:");
        $this->line("   Total: $" . number_format($estimatedCost, 4));
        $this->line("   Per mention: $" . number_format($estimatedCost / $stats->total, 6));
        
        $this->line('');
        
        // Budget check
        $monthlyBudget = 200;
        $percentUsed = ($estimatedCost / $monthlyBudget) * 100;
        
        if ($percentUsed < 10) {
            $this->info("✅ Budget usage: " . number_format($percentUsed, 2) . "% of $" . $monthlyBudget);
        } elseif ($percentUsed < 50) {
            $this->warn("⚠️  Budget usage: " . number_format($percentUsed, 2) . "% of $" . $monthlyBudget);
        } else {
            $this->error("🚨 Budget usage: " . number_format($percentUsed, 2) . "% of $" . $monthlyBudget);
        }
        
        // Show top brands by sentiment
        $this->line('');
        $this->info('🏆 Top Brands by Mentions:');
        
        $topBrands = DB::table('mentions as m')
            ->join('responses as r', 'm.response_id', '=', 'r.id')
            ->whereBetween('r.created_at', [$month, $monthEnd])
            ->select('m.brand_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN m.sentiment = "positive" THEN 1 ELSE 0 END) as pos')
            ->selectRaw('SUM(CASE WHEN m.sentiment = "negative" THEN 1 ELSE 0 END) as neg')
            ->groupBy('m.brand_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();
        
        if ($topBrands->isNotEmpty()) {
            $brandTable = [];
            foreach ($topBrands as $brand) {
                $brandTable[] = [
                    $brand->brand_id,
                    $brand->total,
                    $brand->pos . ' (' . round(($brand->pos / $brand->total) * 100) . '%)',
                    $brand->neg . ' (' . round(($brand->neg / $brand->total) * 100) . '%)',
                ];
            }
            
            $this->table(
                ['Brand', 'Total', 'Positive', 'Negative'],
                $brandTable
            );
        }
        
        return 0;
    }
}