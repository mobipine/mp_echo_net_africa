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
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->foreignId('swahili_question_id')
                ->nullable()
                ->after('id')
                ->constrained('survey_questions')
                ->onDelete('set null');
            
            $table->index('swahili_question_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->dropForeign(['swahili_question_id']);
            $table->dropIndex(['swahili_question_id']);
            $table->dropColumn('swahili_question_id');
        });
    }
};
