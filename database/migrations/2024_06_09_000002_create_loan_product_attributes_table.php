<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')->onDelete('cascade');
            $table->foreignId('loan_attribute_id')->constrained('loan_attributes')->onDelete('cascade');
            $table->string('value')->nullable();
            $table->integer('order')->nullable();//this is for the order of the attributes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_product_attributes');
    }
}; 