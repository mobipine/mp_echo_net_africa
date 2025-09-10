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
        Schema::table('surveys', function (Blueprint $table) {
            //
            $table->unsignedSmallInteger('continue_confirmation_interval')->nullable()->after('final_response');
            $table->string('continue_confirmation_interval_unit')->nullable()->after('continue_confirmation_interval');
            $table->string('continue_confirmation_question')->nullable()->after('continue_confirmation_interval_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            //
            $table->dropColumn('continue_confirmation_interval');
            $table->dropColumn('continue_confirmation_interval_unit');
            $table->dropColumn('continue_confirmation_question');
        });
    }
};
