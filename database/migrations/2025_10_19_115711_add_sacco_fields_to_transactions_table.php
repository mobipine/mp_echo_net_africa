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
            $table->foreignId('savings_account_id')->nullable()->after('repayment_id')->constrained('member_savings_accounts')->onDelete('cascade');
            $table->foreignId('product_subscription_id')->nullable()->after('savings_account_id')->constrained('member_product_subscriptions')->onDelete('cascade');
            $table->string('reference_number', 100)->nullable()->after('description');
            $table->json('metadata')->nullable()->after('reference_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['savings_account_id']);
            $table->dropForeign(['product_subscription_id']);
            $table->dropColumn(['savings_account_id', 'product_subscription_id', 'reference_number', 'metadata']);
        });
    }
};
