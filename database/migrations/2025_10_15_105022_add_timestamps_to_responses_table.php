<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            // Add created_at if it doesn't exist
            if (!Schema::hasColumn('responses', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            
            // Add updated_at if it doesn't exist (optional but good practice)
            if (!Schema::hasColumn('responses', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            $table->dropColumn(['created_at', 'updated_at']);
        });
    }
};