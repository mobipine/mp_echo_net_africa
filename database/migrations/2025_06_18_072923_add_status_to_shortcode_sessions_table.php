<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortcode_sessions', function (Blueprint $table) {
            $table->string('status')->default('ACTIVE')->after('current_question_id'); // Add status column
        });
    }

    public function down(): void
    {
        Schema::table('shortcode_sessions', function (Blueprint $table) {
            $table->dropColumn('status'); // Remove status column
        });
    }
};