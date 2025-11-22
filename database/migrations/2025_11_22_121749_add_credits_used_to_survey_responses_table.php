<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->integer('credits_used')->nullable()->after('inbox_id');
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropColumn('credits_used');
        });
    }
};
