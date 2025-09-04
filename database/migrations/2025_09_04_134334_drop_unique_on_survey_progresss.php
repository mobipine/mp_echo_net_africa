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
        Schema::table('survey_progress', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['survey_id']);
            $table->dropForeign(['member_id']);

            // Drop the unique constraint
            $table->dropUnique(['survey_id', 'member_id']);

            // Re-add the foreign key constraints without the unique constraint
            $table->foreign('survey_id')->references('id')->on('surveys')->onDelete('cascade');
            $table->foreign('member_id')->references('id')->on('members')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_progress', function (Blueprint $table) {
            // Drop the foreign key constraints
            $table->dropForeign(['survey_id']);
            $table->dropForeign(['member_id']);

            // Re-add the unique constraint
            $table->unique(['survey_id', 'member_id']);

            // Re-add the foreign key constraints
            $table->foreign('survey_id')->references('id')->on('surveys')->onDelete('cascade');
            $table->foreign('member_id')->references('id')->on('members')->onDelete('cascade');
        });
    }
};