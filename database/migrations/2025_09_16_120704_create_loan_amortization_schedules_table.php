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
        Schema::create('loan_amortization_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->integer('payment_number'); // 1, 2, 3, etc.
            $table->date('payment_date');
            $table->decimal('principal_payment', 15, 2);
            $table->decimal('interest_payment', 15, 2);
            $table->decimal('total_payment', 15, 2);
            $table->decimal('remaining_balance', 15, 2);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['loan_id', 'payment_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_amortization_schedules');
    }
};