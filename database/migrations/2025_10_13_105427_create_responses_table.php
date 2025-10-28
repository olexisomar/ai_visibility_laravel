<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->onDelete('cascade');
            $table->foreignId('prompt_id')->constrained()->onDelete('cascade');
            $table->longText('raw_answer')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('tokens_in')->nullable();
            $table->integer('tokens_out')->nullable();
            $table->text('prompt_text')->nullable();
            $table->string('prompt_category', 100)->nullable();
            $table->string('intent', 40)->nullable()->index();
            
            $table->index('run_id');
            $table->index('prompt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responses');
    }
};