<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPositionToSurveyQuestionSurveyTable extends Migration
{
    public function up(): void
    {
        Schema::table('survey_question_survey', function (Blueprint $table) {
            $table->integer('position')->default(0)->after('survey_question_id');
        });
    }

    public function down(): void
    {
        Schema::table('survey_question_survey', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
}