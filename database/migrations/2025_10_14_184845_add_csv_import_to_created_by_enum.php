<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only modify if table exists
        if (Schema::hasTable('prompts')) {
            // Check current ENUM values
            $column = DB::selectOne("SHOW COLUMNS FROM prompts WHERE Field = 'created_by'");
            
            if ($column && !str_contains($column->Type, 'csv-import')) {
                DB::statement("ALTER TABLE prompts MODIFY COLUMN created_by ENUM('manual', 'generated', 'csv-import') DEFAULT NULL");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('prompts')) {
            DB::statement("ALTER TABLE prompts MODIFY COLUMN created_by ENUM('manual', 'generated') DEFAULT NULL");
        }
    }
};