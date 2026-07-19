<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_survey', function (Blueprint $table) {
            $table->uuid('dispatch_batch_uuid')->nullable()->after('channel');
            $table->unsignedInteger('queued_count')->nullable()->after('was_dispatched');
            $table->unsignedInteger('skipped_count')->nullable()->after('queued_count');
            $table->json('dispatch_summary')->nullable()->after('skipped_count');
            $table->timestamp('dispatched_at')->nullable()->after('dispatch_summary');
        });
    }

    public function down(): void
    {
        Schema::table('group_survey', function (Blueprint $table) {
            $table->dropColumn([
                'dispatch_batch_uuid',
                'queued_count',
                'skipped_count',
                'dispatch_summary',
                'dispatched_at',
            ]);
        });
    }
};
