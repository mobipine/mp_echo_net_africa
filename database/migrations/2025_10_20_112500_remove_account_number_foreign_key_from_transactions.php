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
            // Drop the foreign key constraint since account_number can now reference
            // either chart_of_accounts.account_code OR group_accounts.account_code
            $table->dropForeign(['account_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Re-add the foreign key constraint if rolling back
            $table->foreign('account_number')
                ->references('account_code')
                ->on('chart_of_accounts')
                ->onDelete('set null');
        });
    }
};

