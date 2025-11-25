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
            $table->unsignedBigInteger('survey_progress_id')->nullable()->after('member_id')
                ->comment('Links SMS to survey progress record');

            $table->foreign('survey_progress_id')
                ->references('id')
                ->on('survey_progress')
                ->onDelete('set null');
        });
    }

    /**
 * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropForeign(['survey_progress_id']);
            $table->dropColumn('survey_progress_id');
        });
    }
};
