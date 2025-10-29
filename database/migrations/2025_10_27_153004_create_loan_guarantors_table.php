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
        Schema::create('loan_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->onDelete('cascade');
            $table->foreignId('guarantor_member_id')->constrained('members')->onDelete('cascade');
            $table->decimal('guaranteed_amount', 15, 2);
            $table->decimal('guarantor_savings_at_guarantee', 15, 2)->nullable()->comment('Snapshot of guarantor\'s savings');
            $table->enum('status', ['pending', 'approved', 'rejected', 'released'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('loan_id');
            $table->index('guarantor_member_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_guarantors');
    }
};
