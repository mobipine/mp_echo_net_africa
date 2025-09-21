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
        Schema::create('loan_product_chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('cascade');
            $table->string('account_type'); // e.g., 'bank', 'loans_receivable', 'interest_income', etc.
            $table->string('account_number'); // Foreign key to chart_of_accounts
            $table->timestamps();
            
            $table->foreign('account_number')->references('account_code')->on('chart_of_accounts')->onDelete('cascade');
            $table->unique(['loan_product_id', 'account_type'], 'loan_product_accounts_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_product_chart_of_accounts');
    }
};
