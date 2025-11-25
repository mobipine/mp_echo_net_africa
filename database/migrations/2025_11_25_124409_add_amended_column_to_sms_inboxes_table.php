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
            $table->string('amended')->nullable()->after('credits_count')
                ->comment('Tracks manual interventions: sysAdmin, auto-retry, etc.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropColumn('amended');
        });
    }
};
