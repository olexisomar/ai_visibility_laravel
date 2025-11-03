<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('automation_settings', function (Blueprint $table) {
            $table->string('notification_email')->nullable()->after('notifications_enabled');
        });
    }

    public function down()
    {
        Schema::table('automation_settings', function (Blueprint $table) {
            $table->dropColumn('notification_email');
        });
    }
};