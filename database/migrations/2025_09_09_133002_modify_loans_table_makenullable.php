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
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->change();
            $table->foreignId('loan_product_id')->nullable()->change();
            $table->decimal('principal_amount', 15, 2)->nullable()->change();
            $table->decimal('interest_rate', 15, 2)->nullable()->change();
            $table->decimal('interest_amount', 15, 2)->nullable()->change();
            $table->decimal('repayment_amount', 15, 2)->nullable()->change();
            $table->date('release_date')->nullable()->change();
            $table->integer('loan_duration')->nullable()->change();
            $table->string('status')->nullable()->change();
            $table->string('loan_number')->nullable()->change();
            $table->dateTime('issued_at')->nullable()->change();
            $table->dateTime('due_at')->nullable()->change();
            $table->string('loan_purpose')->nullable()->change();
            $table->string('repayment_schedule')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable(false)->change();
            $table->foreignId('loan_product_id')->nullable(false)->change();
            $table->decimal('principal_amount', 15, 2)->nullable(false)->change();
            $table->decimal('interest_rate', 15, 2)->nullable(false)->change();
            $table->decimal('interest_amount', 15, 2)->nullable(false)->change();
            $table->decimal('repayment_amount', 15, 2)->nullable(false)->change();
            $table->date('release_date')->nullable(false)->change();
            $table->integer('loan_duration')->nullable(false)->change();
            $table->string('status')->nullable(false)->change();
            $table->string('loan_number')->nullable(false)->change();
            $table->dateTime('issued_at')->nullable(false)->change();
            $table->dateTime('due_at')->nullable(false)->change();
            $table->string('loan_purpose')->nullable(false)->change();
            $table->string('repayment_schedule')->nullable(false)->change();
        });
    }
};