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
        // Drop the unnecessary tables
        Schema::dropIfExists('creditor_transactions');
        Schema::dropIfExists('debtor_transactions');
        Schema::dropIfExists('chart_of_accounts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the tables if needed (for rollback)
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('debtor_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts');
            $table->string('transaction_type')->nullable();
            $table->string('dr_cr')->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('transaction_date');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('creditor_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts');
            $table->string('transaction_type')->nullable();
            $table->string('dr_cr')->nullable();
            $table->decimal('amount', 10, 2);
            $table->date('transaction_date');
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }
};