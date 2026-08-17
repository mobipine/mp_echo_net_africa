<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->index(
                ['survey_id', 'msisdn', 'question_id', 'id'],
                'survey_responses_report_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropIndex('survey_responses_report_lookup_idx');
        });
    }
};
