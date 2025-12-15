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
        Schema::create('ussd_flows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('flow_type', ['loan_repayment', 'member_search', 'custom'])->default('custom');
            $table->json('flow_definition'); // Stores nodes and edges
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('flow_type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ussd_flows');
    }
};
