<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Settings table (single row config)
        Schema::create('automation_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('schedule', ['paused', 'weekly'])->default('weekly');
            $table->enum('default_source', ['gpt', 'google_aio', 'all'])->default('all');
            $table->string('schedule_day')->default('monday'); // For weekly
            $table->string('schedule_time')->default('09:00'); // 24h format
            $table->integer('max_runs_per_day')->default(10);
            $table->boolean('notifications_enabled')->default(false);
            $table->timestamps();
        });

        // Runs history table
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('trigger_type', ['manual', 'scheduled'])->default('manual');
            $table->enum('source', ['gpt', 'google_aio', 'all']);
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->integer('prompts_processed')->default(0);
            $table->integer('new_mentions')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('triggered_by')->nullable(); // Store username/email instead of FK
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['status', 'created_at']);
            $table->index('trigger_type');
            $table->index('source');
        });

        // Insert default settings
        DB::table('automation_settings')->insert([
            'schedule' => 'weekly',
            'default_source' => 'all',
            'schedule_day' => 'monday',
            'schedule_time' => '09:00',
            'max_runs_per_day' => 10,
            'notifications_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_settings');
    }
};