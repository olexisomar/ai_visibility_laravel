<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('text', 255);
            $table->string('category', 160)->nullable();
            $table->foreignId('persona_id')->nullable()->constrained()->onDelete('set null');
            $table->boolean('is_branded')->default(false);
            $table->json('notes')->nullable();
            $table->integer('rank')->nullable();
            $table->string('source', 32);
            $table->string('lang', 8)->nullable();
            $table->string('geo', 8)->nullable();
            $table->string('seed_term', 255)->nullable();
            $table->dateTime('collected_at')->useCurrent();
            $table->string('normalized', 255);
            $table->char('hash_norm', 40);
            $table->string('topic_cluster', 120)->nullable();
            $table->json('serp_features')->nullable();
            $table->tinyInteger('confidence')->unsigned()->nullable();
            $table->decimal('score_auto', 5, 2)->nullable();
            $table->enum('status', ['new', 'approved', 'rejected'])->default('new');
            $table->integer('search_volume')->nullable();
            $table->dateTime('volume_checked_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->foreignId('prompt_id')->nullable()->constrained()->onDelete('set null');
            
            $table->unique('hash_norm');
            $table->unique(['category', 'hash_norm', 'persona_id']);
            $table->index(['status', 'score_auto']);
            $table->index('collected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_suggestions');
    }
};