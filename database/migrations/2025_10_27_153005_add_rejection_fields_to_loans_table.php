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
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->foreignId('rejected_by')->nullable()->constrained('users')->onDelete('set null')->after('rejected_at');
            $table->text('rejection_reason')->nullable()->after('rejected_by');
            $table->string('rejection_type')->nullable()->after('rejection_reason')->comment('e.g., insufficient_guarantors, insufficient_savings, etc.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejected_at', 'rejected_by', 'rejection_reason', 'rejection_type']);
        });
    }
};
