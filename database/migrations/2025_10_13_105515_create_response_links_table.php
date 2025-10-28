<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('response_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained()->onDelete('cascade');
            $table->text('url');
            $table->string('anchor', 512)->nullable();
            $table->string('source', 255)->nullable();
            $table->string('domain', 255)->nullable()->index();
            
            $table->index('response_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('response_links');
    }
};