<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('category', 100)->nullable()->index();
            $table->foreignId('persona_id')->nullable()->constrained()->onDelete('set null');
            $table->text('prompt');
            $table->string('source', 32)->nullable();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->string('lang', 8)->nullable();
            $table->string('geo', 8)->nullable();
            $table->dateTime('first_seen')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->enum('status', ['suggested', 'approved', 'active', 'paused', 'archived'])->default('active');
            $table->json('serp_features')->nullable();
            $table->decimal('trend_delta', 6, 2)->nullable();
            $table->integer('search_volume')->nullable();
            $table->boolean('is_paused')->default(false)->index();
            $table->decimal('score_auto', 6, 2)->nullable();
            $table->string('topic_cluster', 120)->nullable();
            $table->char('hash_norm', 40)->unique()->nullable();
            $table->string('notes', 255)->nullable();
            $table->enum('created_by', ['manual', 'generated'])->default('generated')->index();
            $table->dateTime('volume_checked_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompts');
    }
};