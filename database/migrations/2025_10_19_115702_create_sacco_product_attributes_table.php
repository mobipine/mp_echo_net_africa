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
        Schema::create('sacco_product_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 50); // 'string', 'integer', 'decimal', 'boolean', 'date', 'select', 'json'
            $table->text('options')->nullable(); // JSON for select options or validation rules
            $table->text('description')->nullable();
            $table->json('applicable_product_types')->nullable(); // Which product types can use this attribute
            $table->boolean('is_required')->default(false);
            $table->text('default_value')->nullable();
            $table->timestamps();
            
            $table->index('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacco_product_attributes');
    }
};
