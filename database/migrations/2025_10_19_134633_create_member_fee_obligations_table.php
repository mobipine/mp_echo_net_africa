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
        Schema::create('member_fee_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            $table->foreignId('sacco_product_id')->constrained('sacco_products')->onDelete('restrict');
            $table->decimal('amount_due', 15, 2);
            $table->decimal('amount_paid', 15, 2)->default(0.00);
            $table->date('due_date');
            $table->enum('status', ['pending', 'partially_paid', 'paid', 'waived'])->default('pending');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['member_id', 'status']);
            $table->index(['sacco_product_id', 'status']);
            $table->unique(['member_id', 'sacco_product_id'], 'unique_member_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_fee_obligations');
    }
};
