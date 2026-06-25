<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->uuid('dispatch_batch_uuid')->nullable()->after('survey_progress_id');
            $table->string('dedupe_key')->nullable()->after('dispatch_batch_uuid');
            $table->index('dispatch_batch_uuid');
            $table->unique('dedupe_key');
        });
    }

    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropIndex(['dispatch_batch_uuid']);
            $table->dropColumn(['dispatch_batch_uuid', 'dedupe_key']);
        });
    }
};
