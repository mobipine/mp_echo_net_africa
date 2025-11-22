<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->integer('credits_used')->nullable()->after('is_reminder');
        });
    }

    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropColumn('credits_used');
        });
    }
};
