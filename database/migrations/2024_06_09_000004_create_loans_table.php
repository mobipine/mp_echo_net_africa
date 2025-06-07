<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('cascade');
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_rate', 15, 2);
            $table->decimal('interest_amount', 15, 2);
            $table->decimal('repayment_amount', 15, 2);
            $table->date('release_date');
            $table->integer('loan_duration');
            $table->string('status');
            $table->string('loan_number');
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->string('loan_purpose')->nullable();
            $table->string('repayment_schedule')->nullable();//store the repayment schedule as a json that will be easily editable
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
}; 