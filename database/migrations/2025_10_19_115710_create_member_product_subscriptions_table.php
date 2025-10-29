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
        Schema::create('member_product_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            $table->foreignId('sacco_product_id')->constrained('sacco_products')->onDelete('restrict');
            $table->date('subscription_date');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'completed', 'cancelled', 'suspended'])->default('active');
            $table->decimal('total_paid', 15, 2)->default(0.00);
            $table->decimal('total_expected', 15, 2)->nullable();
            $table->integer('payment_count')->default(0);
            $table->date('last_payment_date')->nullable();
            $table->date('next_payment_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('member_id');
            $table->index('sacco_product_id');
            $table->index('status');
            $table->index('next_payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_product_subscriptions');
    }
};
