<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only modify if table exists (it's created by a later migration)
        if (Schema::hasTable('sms_inboxes')) {
            Schema::table('sms_inboxes', function (Blueprint $table) {
                if (!Schema::hasColumn('sms_inboxes', 'group_ids')) {
                    $table->json('group_ids')->nullable()->after('message');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropColumn('group_ids');
        });
    }
};
