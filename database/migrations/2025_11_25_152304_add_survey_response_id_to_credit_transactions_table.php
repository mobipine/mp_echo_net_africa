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
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->foreignId('survey_response_id')->nullable()->after('sms_inbox_id')
                ->constrained('survey_responses')->onDelete('set null')
                ->comment('Reference to survey response if transaction is from receiving response');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropForeign(['survey_response_id']);
            $table->dropColumn('survey_response_id');
        });
    }
};
