<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->text('failure_reason')->nullable()->after('delivery_status_desc');
            $table->integer('retries')->default(0)->after('failure_reason');
            $table->integer('credits_count')->default(1)->after('retries')
                ->comment('Number of credits required (1 credit = 160 characters)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropColumn(['failure_reason', 'retries', 'credits_count']);
        });
    }
};
