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
        Schema::create('sacco_product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_product_id')->constrained('sacco_products')->onDelete('cascade');
            $table->foreignId('attribute_id')->constrained('sacco_product_attributes')->onDelete('cascade');
            $table->text('value')->nullable();
            $table->timestamps();
            
            $table->unique(['sacco_product_id', 'attribute_id'], 'unique_product_attribute');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacco_product_attribute_values');
    }
};
