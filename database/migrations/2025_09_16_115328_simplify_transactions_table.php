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
        Schema::table('transactions', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['chart_of_account_id']);
            // Drop the column
            $table->dropColumn('chart_of_account_id');
            
            // Add new columns for simplified transaction tracking
            $table->string('account_name')->after('id'); // e.g., 'Loans Receivable', 'Bank', 'Cash'
            $table->foreignId('loan_id')->nullable()->constrained('loans')->onDelete('cascade')->after('account_name');
            $table->foreignId('member_id')->nullable()->constrained('members')->onDelete('cascade')->after('loan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Remove the new columns
            $table->dropForeign(['loan_id']);
            $table->dropForeign(['member_id']);
            $table->dropColumn(['account_name', 'loan_id', 'member_id']);
            
            // Add back the chart_of_account_id column
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->after('id');
        });
    }
};