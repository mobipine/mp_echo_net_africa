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
        Schema::table('loan_product_attributes', function (Blueprint $table) {
            $table->string('account_number')->nullable()->after('value');
            $table->foreign('account_number')->references('account_code')->on('chart_of_accounts')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_product_attributes', function (Blueprint $table) {
            $table->dropForeign(['account_number']);
            $table->dropColumn('account_number');
        });
    }
};
