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
            $table->unsignedSmallInteger('question_interval')->nullable()->after('was_dispatched');
            $table->string('question_interval_unit')->nullable()->after('question_interval');
   
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('group_survey', function (Blueprint $table) {
            //
        });
    }
};
