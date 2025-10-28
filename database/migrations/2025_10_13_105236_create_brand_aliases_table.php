<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('brand_id', 100);
            $table->string('alias', 255);
            
            $table->unique(['brand_id', 'alias']);
            $table->foreign('brand_id')->references('id')->on('brands')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_aliases');
    }
};