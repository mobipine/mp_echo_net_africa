<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('trigger_word');
            $table->text('final_response')->nullable();
            $table->enum('status', ['Active', 'Inactive']);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('participant_uniqueness')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveys');
    }
};
