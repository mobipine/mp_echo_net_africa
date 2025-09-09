<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->json('group_ids')->nullable()->after('message'); // Store multiple group IDs as JSON
        });
    }

    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropColumn('group_ids');
        });
    }
};
