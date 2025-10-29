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
        Schema::table('loans', function (Blueprint $table) {
            // Foreign keys
            $table->foreignId('member_id')->nullable()->change();
            $table->foreignId('loan_product_id')->nullable()->change();
            
            // Decimal fields
            $table->decimal('principal_amount', 15, 2)->nullable()->change();
            $table->decimal('interest_rate', 15, 2)->nullable()->change();
            $table->decimal('interest_amount', 15, 2)->nullable()->change();
            $table->decimal('repayment_amount', 15, 2)->nullable()->change();
            
            // Date fields
            $table->date('release_date')->nullable()->change();
            $table->integer('loan_duration')->nullable()->change();
            
            // String fields
            $table->string('status')->nullable()->change();
            $table->string('loan_number')->nullable()->change();
            $table->string('repayment_schedule')->nullable()->change();
            
            // Already nullable fields don't need to be changed:
            // - loan_purpose (nullable)
            // - issued_at (nullable)
            // - due_at (nullable)
            // - approved_by (nullable)
            // - approved_at (nullable)
            // - session_data (nullable)
            // - rejected_at (nullable)
            // - rejected_by (nullable)
            // - rejection_reason (nullable)
            // - rejection_type (nullable)
            
            // Add is_completed field if it doesn't exist
            if (!Schema::hasColumn('loans', 'is_completed')) {
                $table->boolean('is_completed')->default(false)->after('session_data');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Revert foreign keys
            $table->foreignId('member_id')->nullable(false)->change();
            $table->foreignId('loan_product_id')->nullable(false)->change();
            
            // Revert decimal fields
            $table->decimal('principal_amount', 15, 2)->nullable(false)->change();
            $table->decimal('interest_rate', 15, 2)->nullable(false)->change();
            $table->decimal('interest_amount', 15, 2)->nullable(false)->change();
            $table->decimal('repayment_amount', 15, 2)->nullable(false)->change();
            
            // Revert date fields
            $table->date('release_date')->nullable(false)->change();
            $table->integer('loan_duration')->nullable(false)->change();
            
            // Revert string fields
            $table->string('status')->nullable(false)->change();
            $table->string('loan_number')->nullable(false)->change();
            $table->string('repayment_schedule')->nullable(false)->change();
        });
    }
};
