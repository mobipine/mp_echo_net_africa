<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortcode_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('msisdn'); // Phone number of the user
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_question_id')->nullable()->constrained('survey_questions')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shortcode_sessions');
    }
};