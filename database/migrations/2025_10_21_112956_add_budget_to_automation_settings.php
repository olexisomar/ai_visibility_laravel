<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_settings', function (Blueprint $table) {
            $table->decimal('monthly_budget', 10, 2)->default(200.00)->after('notifications_enabled');
            $table->decimal('current_month_spent', 10, 2)->default(0.00)->after('monthly_budget');
            $table->date('budget_reset_date')->nullable()->after('current_month_spent');
        });
    }

    public function down(): void
    {
        Schema::table('automation_settings', function (Blueprint $table) {
            $table->dropColumn(['monthly_budget', 'current_month_spent', 'budget_reset_date']);
        });
    }
};