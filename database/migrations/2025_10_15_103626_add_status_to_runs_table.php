<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // Add status column if it doesn't exist
            if (!Schema::hasColumn('runs', 'status')) {
                $table->enum('status', ['running', 'completed', 'stopped', 'failed'])
                    ->default('running')
                    ->after('temp');
            }
            
            // Add started_at if it doesn't exist
            if (!Schema::hasColumn('runs', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('status');
            }
            
            // Add finished_at if it doesn't exist
            if (!Schema::hasColumn('runs', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['status', 'started_at', 'finished_at']);
        });
    }
};