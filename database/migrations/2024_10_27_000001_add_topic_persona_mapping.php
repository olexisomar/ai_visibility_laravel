<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Add brand_id to topics if missing
        if (!Schema::hasColumn('topics', 'brand_id')) {
            DB::statement("ALTER TABLE `topics` ADD COLUMN `brand_id` VARCHAR(100) NULL AFTER `name`");
            DB::statement("ALTER TABLE `topics` ADD CONSTRAINT `fk_topics_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE SET NULL ON UPDATE CASCADE");
        }
        
        // Add is_deleted to topics if missing
        if (!Schema::hasColumn('topics', 'is_deleted')) {
            DB::statement("ALTER TABLE `topics` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`");
        }

        // Create pivot table using raw SQL to match exact structure
        DB::statement("
            CREATE TABLE `topic_persona` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `topic_id` INT(11) NOT NULL,
                `persona_id` INT(11) NOT NULL,
                `created_at` TIMESTAMP NULL DEFAULT NULL,
                `updated_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_topic_persona` (`topic_id`, `persona_id`),
                KEY `topic_persona_persona_id_foreign` (`persona_id`),
                CONSTRAINT `topic_persona_topic_id_foreign` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
                CONSTRAINT `topic_persona_persona_id_foreign` FOREIGN KEY (`persona_id`) REFERENCES `personas` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        // Add topic_id to raw_suggestions if not exists
        if (!Schema::hasColumn('raw_suggestions', 'topic_id')) {
            DB::statement("ALTER TABLE `raw_suggestions` ADD COLUMN `topic_id` INT(11) NULL AFTER `persona_id`");
            DB::statement("ALTER TABLE `raw_suggestions` ADD INDEX `idx_raw_suggestions_topic` (`topic_id`)");
            DB::statement("ALTER TABLE `raw_suggestions` ADD CONSTRAINT `fk_raw_suggestions_topic` FOREIGN KEY (`topic_id`) REFERENCES `topics`(`id`) ON DELETE SET NULL");
        }

        // Add topic_id to prompts if not exists
        if (!Schema::hasColumn('prompts', 'topic_id')) {
            DB::statement("ALTER TABLE `prompts` ADD COLUMN `topic_id` INT(11) NULL AFTER `persona_id`");
            DB::statement("ALTER TABLE `prompts` ADD INDEX `idx_prompts_topic` (`topic_id`)");
            DB::statement("ALTER TABLE `prompts` ADD CONSTRAINT `fk_prompts_topic` FOREIGN KEY (`topic_id`) REFERENCES `topics`(`id`) ON DELETE SET NULL");
        }
    }

    public function down()
    {
        // Drop in reverse order
        DB::statement("DROP TABLE IF EXISTS `topic_persona`");
        
        if (Schema::hasColumn('raw_suggestions', 'topic_id')) {
            DB::statement("ALTER TABLE `raw_suggestions` DROP FOREIGN KEY `fk_raw_suggestions_topic`");
            DB::statement("ALTER TABLE `raw_suggestions` DROP COLUMN `topic_id`");
        }
        
        if (Schema::hasColumn('prompts', 'topic_id')) {
            DB::statement("ALTER TABLE `prompts` DROP FOREIGN KEY `fk_prompts_topic`");
            DB::statement("ALTER TABLE `prompts` DROP COLUMN `topic_id`");
        }
        
        if (Schema::hasColumn('topics', 'brand_id')) {
            DB::statement("ALTER TABLE `topics` DROP FOREIGN KEY `fk_topics_brand`");
            DB::statement("ALTER TABLE `topics` DROP COLUMN `brand_id`");
        }
        
        if (Schema::hasColumn('topics', 'is_deleted')) {
            DB::statement("ALTER TABLE `topics` DROP COLUMN `is_deleted`");
        }
    }
};