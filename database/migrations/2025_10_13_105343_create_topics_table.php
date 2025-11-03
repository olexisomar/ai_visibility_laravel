<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // First, add missing columns to topics table
        if (!Schema::hasColumn('topics', 'brand_id')) {
            Schema::table('topics', function (Blueprint $table) {
                $table->string('brand_id', 100)->nullable()->after('name');
                $table->foreign('brand_id')->references('id')->on('brands')->onDelete('set null');
            });
        }
        
        if (!Schema::hasColumn('topics', 'is_deleted')) {
            Schema::table('topics', function (Blueprint $table) {
                $table->tinyInteger('is_deleted')->default(0)->after('is_active');
            });
        }

        // Create pivot table for topic-persona relationships
        Schema::create('topic_persona', function (Blueprint $table) {
            $table->integer('id', true, true); // Auto-increment unsigned integer
            
            // Use unsigned integer to match INT(11) in your existing tables
            $table->integer('topic_id')->unsigned();
            $table->integer('persona_id')->unsigned();
            
            $table->timestamps();
            
            // Add foreign keys
            $table->foreign('topic_id')
                  ->references('id')
                  ->on('topics')
                  ->onDelete('cascade');
                  
            $table->foreign('persona_id')
                  ->references('id')
                  ->on('personas')
                  ->onDelete('cascade');
            
            // Ensure unique combinations
            $table->unique(['topic_id', 'persona_id'], 'uq_topic_persona');
        });

        // Add topic_id to raw_suggestions if not exists
        if (!Schema::hasColumn('raw_suggestions', 'topic_id')) {
            Schema::table('raw_suggestions', function (Blueprint $table) {
                $table->integer('topic_id')->unsigned()->nullable()->after('persona_id');
                $table->index('topic_id');
                
                $table->foreign('topic_id')
                      ->references('id')
                      ->on('topics')
                      ->onDelete('set null');
            });
        }

        // Add topic_id to prompts if not exists
        if (!Schema::hasColumn('prompts', 'topic_id')) {
            Schema::table('prompts', function (Blueprint $table) {
                $table->integer('topic_id')->unsigned()->nullable()->after('persona_id');
                $table->index('topic_id');
                
                $table->foreign('topic_id')
                      ->references('id')
                      ->on('topics')
                      ->onDelete('set null');
            });
        }
    }

    public function down()
    {
        // Drop foreign keys and columns in reverse order
        Schema::dropIfExists('topic_persona');
        
        if (Schema::hasColumn('raw_suggestions', 'topic_id')) {
            Schema::table('raw_suggestions', function (Blueprint $table) {
                $table->dropForeign(['topic_id']);
                $table->dropColumn('topic_id');
            });
        }
        
        if (Schema::hasColumn('prompts', 'topic_id')) {
            Schema::table('prompts', function (Blueprint $table) {
                $table->dropForeign(['topic_id']);
                $table->dropColumn('topic_id');
            });
        }
        
        if (Schema::hasColumn('topics', 'brand_id')) {
            Schema::table('topics', function (Blueprint $table) {
                $table->dropForeign(['brand_id']);
                $table->dropColumn('brand_id');
            });
        }
        
        if (Schema::hasColumn('topics', 'is_deleted')) {
            Schema::table('topics', function (Blueprint $table) {
                $table->dropColumn('is_deleted');
            });
        }
    }
};