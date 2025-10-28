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
        Schema::create('sacco_product_chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_product_id')->constrained('sacco_products')->onDelete('cascade');
            $table->string('account_type', 100); // e.g., 'bank', 'savings_account', 'contribution_receivable', etc.
            $table->string('account_number', 50);
            $table->timestamps();
            
            $table->foreign('account_number')->references('account_code')->on('chart_of_accounts')->onDelete('cascade');
            $table->unique(['sacco_product_id', 'account_type'], 'unique_product_account_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacco_product_chart_of_accounts');
    }
};
