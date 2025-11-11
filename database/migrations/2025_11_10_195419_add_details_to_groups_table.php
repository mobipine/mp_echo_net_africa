<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('kra_pin')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_branch')->nullable();
            $table->enum('meeting_frequency', ['weekly', 'biweekly', 'monthly', 'bimonthly'])->nullable();
            $table->string('meeting_day')->nullable(); // e.g., Monday, Tuesday...
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn([
                'kra_pin',
                'bank_name',
                'bank_account_number',
                'bank_branch',
                'meeting_frequency',
                'meeting_day',
            ]);
        });
    }
};
