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
        Schema::create('group_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->onDelete('restrict');
            $table->string('account_code', 50)->unique();
            $table->string('account_name');
            $table->string('account_type', 100); 
            // Types: 'group_bank', 'group_loans_receivable', 'group_interest_receivable',
            // 'group_loan_charges_receivable', 'group_member_savings', 'group_capital_payable',
            // 'group_interest_income', 'group_loan_charges_income', 'group_contribution_income'
            $table->enum('account_nature', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->string('parent_account_code', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('opening_balance', 15, 2)->default(0.00);
            $table->date('opening_date')->nullable();
            $table->timestamps();
            
            $table->foreign('parent_account_code')->references('account_code')->on('chart_of_accounts')->onDelete('set null');
            $table->index(['group_id', 'account_type']);
            $table->index('account_type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_accounts');
    }
};

