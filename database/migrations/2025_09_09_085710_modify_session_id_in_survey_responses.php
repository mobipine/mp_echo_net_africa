<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['session_id']);

            // Update the foreign key to reference the survey_progress table
            $table->foreign('session_id')->references('id')->on('survey_progress')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            // Drop the foreign key constraint referencing survey_progress
            $table->dropForeign(['session_id']);

            
        });
    }
};