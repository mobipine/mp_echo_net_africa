<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_progress', function (Blueprint $table) {
            $table->unsignedInteger('number_of_reminders')->default(0)->after('has_responded');
        });
    }

    public function down(): void
    {
        Schema::table('survey_progress', function (Blueprint $table) {
            $table->dropColumn('number_of_reminders');
        });
    }
};
