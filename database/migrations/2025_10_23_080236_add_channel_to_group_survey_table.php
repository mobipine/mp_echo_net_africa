<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_survey', function (Blueprint $table) {
            $table->string('channel')->default('sms')->after('survey_id');
        });
    }

    public function down(): void
    {
        Schema::table('group_surveys', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
