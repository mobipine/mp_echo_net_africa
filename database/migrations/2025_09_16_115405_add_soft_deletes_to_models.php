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
        // Add soft deletes to existing tables
        Schema::table('loans', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};