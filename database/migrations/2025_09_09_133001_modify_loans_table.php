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
        // Migration to add new columns to loans table
        Schema::table('loans', function (Blueprint $table) {
            $table->json('wizard_data')->nullable()->after('status');
            $table->json('amortization_schedule')->nullable()->after('repayment_schedule');
            $table->boolean('is_completed')->default(false)->after('wizard_data');
            $table->timestamp('submitted_at')->nullable()->after('is_completed');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->after('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback the migration by dropping the added columns
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['wizard_data', 'amortization_schedule', 'is_completed', 'submitted_at', 'approved_at', 'approved_by']);
        });
    }
};
