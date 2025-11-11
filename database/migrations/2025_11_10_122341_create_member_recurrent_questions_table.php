<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_recurrent_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->onDelete('cascade');
            $table->foreignId('question_id')->constrained('survey_questions')->onDelete('cascade');
            $table->smallInteger('sent_count')->unsigned()->default(0);
            $table->timestamp('next_dispatch_at')->nullable();
            $table->timestamps();

            // removed unique constraint to allow multiple records per member-question
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_recurrent_questions');
    }
};
