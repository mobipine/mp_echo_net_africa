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
            //add answer_strictness(open_ended/multiple_choice) column
            //add possible_answers column (json)
            $table->enum('answer_strictness', ['Open-Ended', 'Multiple Choice'])->default('Open-Ended')->nullable();
            $table->json('possible_answers')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->dropColumn('answer_strictness');
            $table->dropColumn('possible_answers');
        });
    }
};
