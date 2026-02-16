<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Allows USSD-recorded repayments when the official has no linked user account.
     */
    public function up(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropForeign(['recorded_by']);
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->unsignedBigInteger('recorded_by')->nullable()->change();
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropForeign(['recorded_by']);
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->unsignedBigInteger('recorded_by')->nullable(false)->change();
        });

        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->foreign('recorded_by')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
