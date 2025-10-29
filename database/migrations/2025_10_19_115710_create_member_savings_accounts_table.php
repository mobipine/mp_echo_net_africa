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
        Schema::create('member_savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            $table->foreignId('sacco_product_id')->constrained('sacco_products')->onDelete('restrict');
            $table->string('account_number', 50)->unique();
            $table->date('opening_date');
            $table->enum('status', ['active', 'dormant', 'closed'])->default('active');
            $table->date('closed_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->unique(['member_id', 'sacco_product_id'], 'unique_member_product');
            $table->index('member_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_savings_accounts');
    }
};
