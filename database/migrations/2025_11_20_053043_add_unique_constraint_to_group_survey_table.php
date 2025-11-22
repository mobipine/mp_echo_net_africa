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
        Schema::table('group_survey', function (Blueprint $table) {
            // Add unique constraint to prevent duplicate survey assignments
            // This ensures that a group can't have the same survey scheduled multiple times
            // for the same start time (which would cause duplicate messages)
            $table->unique(['group_id', 'survey_id', 'starts_at'], 'unique_group_survey_schedule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_survey', function (Blueprint $table) {
            $table->dropUnique('unique_group_survey_schedule');
        });
    }
};
