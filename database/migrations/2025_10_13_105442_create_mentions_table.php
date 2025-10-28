<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained()->onDelete('cascade');
            $table->string('brand_id', 100);
            $table->string('found_alias', 255)->nullable();
            $table->string('sentiment', 20)->nullable()->index();
            
            $table->foreign('brand_id')->references('id')->on('brands')->onDelete('cascade');
            $table->index('response_id');
            $table->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
    }
};