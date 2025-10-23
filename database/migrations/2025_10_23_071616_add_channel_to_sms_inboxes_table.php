<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->string('channel')->nullable()->after('group_ids'); 
            // You can change 'group_ids' to whichever column you want it to appear after
        });
    }

    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
