<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('model', 100);
            $table->double('temp')->nullable();
            $table->timestamp('run_at')->useCurrent();
            
            $table->index('model');
            $table->index('run_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
    }
};