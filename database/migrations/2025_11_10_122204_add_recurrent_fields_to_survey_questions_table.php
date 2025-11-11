<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->boolean('is_recurrent')->default(false)->after('possible_answers');
            $table->smallInteger('recur_interval')->unsigned()->nullable()->after('is_recurrent');
            $table->enum('recur_unit', ['seconds','minutes','hours','days','weeks','months'])->nullable()->after('recur_interval');
            $table->smallInteger('recur_times')->unsigned()->nullable()->after('recur_unit'); // how many times to repeat
        });
    }

    public function down(): void
    {
        Schema::table('survey_questions', function (Blueprint $table) {
            $table->dropColumn(['is_recurrent', 'recur_interval', 'recur_unit', 'recur_times']);
        });
    }
};
