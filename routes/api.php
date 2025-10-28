<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\PromptController;
use App\Http\Controllers\Api\MentionController;
use App\Http\Controllers\Api\TopicController;
use App\Http\Controllers\Api\PersonaController;
use App\Http\Controllers\Api\SuggestionController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\RunController;
use App\Http\Controllers\Api\AutomationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->group(function () {
    
    // ==================== BRANDS ====================
    Route::prefix('brands')->name('admin.brands.')->group(function () {
        Route::get('/', [BrandController::class, 'index'])->name('index');
        Route::post('/', [BrandController::class, 'store'])->name('store');
        Route::delete('/{id}', [BrandController::class, 'destroy'])->name('destroy');
        Route::post('/primary', [BrandController::class, 'setPrimary'])->name('setPrimary');
        Route::get('/export', [BrandController::class, 'export'])->name('export');
        Route::post('/import', [BrandController::class, 'import'])->name('import');
    });

    // ==================== PROMPTS ====================
    Route::prefix('prompts')->name('admin.prompts.')->group(function () {
        Route::get('/', [PromptController::class, 'index'])->name('index');
        Route::get('/status', [PromptController::class, 'status'])->name('status');
        Route::post('/', [PromptController::class, 'store'])->name('store');                          // ← SAVE
        Route::delete('/{id}', [PromptController::class, 'destroy'])->name('destroy');               // ← DELETE
        Route::post('/{id}/toggle-pause', [PromptController::class, 'togglePause'])->name('togglePause'); // ← PAUSE
        Route::post('/bulk-pause', [PromptController::class, 'bulkPause'])->name('bulkPause');
        Route::post('/bulk-resume', [PromptController::class, 'bulkResume'])->name('bulkResume');
        Route::post('/bulk-delete', [PromptController::class, 'bulkDelete'])->name('bulkDelete');
        Route::get('/export', [PromptController::class, 'export'])->name('export');
        Route::post('/import', [PromptController::class, 'import'])->name('import');
    });

    // ==================== MENTIONS ====================
    Route::prefix('mentions')->name('admin.mentions.')->group(function () {
        // Mentions export
        Route::get('/export', [MentionController::class, 'export']);
        Route::get('/export-sheets', [MentionController::class, 'exportToSheets']);
        Route::get('/export-windsor', [MentionController::class, 'exportForWindsor'])->name('exportWindsor');
        Route::get('/', [MentionController::class, 'index'])->name('index');
        Route::get('/{id}', [MentionController::class, 'show'])->name('show');
    });

    // ==================== TOPICS ====================
    Route::prefix('topics')->name('admin.topics.')->group(function () {
        Route::get('/', [TopicController::class, 'index'])->name('index');
        Route::post('/', [TopicController::class, 'store'])->name('store');
        Route::post('/set-active', [TopicController::class, 'setActive'])->name('setActive');
        Route::post('/touch', [TopicController::class, 'touch'])->name('touch');
    });

    // ==================== PERSONAS ====================
    Route::prefix('personas')->name('admin.personas.')->group(function () {
        Route::get('/', [PersonaController::class, 'index'])->name('index');
        Route::post('/', [PersonaController::class, 'store'])->name('store');
        Route::post('/delete', [PersonaController::class, 'destroy'])->name('destroy');
    });

    // ==================== SUGGESTIONS ====================
    Route::prefix('suggestions')->name('admin.suggestions.')->group(function () {
        Route::get('/', [SuggestionController::class, 'index'])->name('index');
        Route::get('/count', [SuggestionController::class, 'count'])->name('count');
        Route::post('/{id}/approve', [SuggestionController::class, 'approve'])->name('approve');
        Route::post('/{id}/reject', [SuggestionController::class, 'reject'])->name('reject');
        Route::post('/bulk-approve', [SuggestionController::class, 'bulkApprove'])->name('bulkApprove');
        Route::post('/bulk-reject', [SuggestionController::class, 'bulkReject'])->name('bulkReject');
    });

    // ==================== METRICS ====================
    Route::prefix('metrics')->name('admin.metrics.')->group(function () {
        Route::get('/', [MetricsController::class, 'index'])->name('index');
        Route::get('/scope', [MetricsController::class, 'scope'])->name('scope');
    });
    // ==================== PERFORMANCE ====================
    Route::get('performance', [PerformanceController::class, 'index'])->name('admin.performance.index');

    // ==================== RUNS (GPT/GAIO TRACKING) ====================
    Route::prefix('runs')->name('admin.runs.')->group(function () {
        Route::get('/', [RunController::class, 'index'])->name('index');
        Route::get('/{id}', [RunController::class, 'show'])->name('show');
        Route::post('/start', [RunController::class, 'start'])->name('start');
        Route::post('/{id}/stop', [RunController::class, 'stop'])->name('stop');
        Route::post('/aio/start', [RunController::class, 'startAIO'])->name('admin.aio.start');
    });

    // Legacy endpoint alias (for the GET /api/admin/gpt call)
    Route::get('/gpt', [RunController::class, 'start'])->name('admin.gpt');
    Route::get('/aio', [RunController::class, 'startAIO'])->name('admin.aio');

    // ==================== Automation endpoints ====================
    Route::get('/automation/settings', [AutomationController::class, 'getSettings']);
    Route::post('/automation/settings', [AutomationController::class, 'updateSettings']);
    Route::post('/automation/run-once', [AutomationController::class, 'runOnce']);
    Route::get('/automation/runs', [AutomationController::class, 'getRecentRuns']);
    Route::get('/automation/runs/{id}', [AutomationController::class, 'getRunStatus']);
    
    // ==================== RESPONSES ====================
    Route::get('/responses/{id}', [\App\Http\Controllers\Api\ResponseController::class, 'show'])->name('admin.responses.show');
    
    // Budget endpoints
    Route::get('/automation/budget', [AutomationController::class, 'getBudgetStats']);
    Route::post('/automation/budget', [AutomationController::class, 'updateBudget']);

});