<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SMS Credits balance table
        Schema::create('sms_credits', function (Blueprint $table) {
            $table->id();
            $table->integer('balance')->default(0)->comment('Current credit balance');
            $table->timestamps();
        });

        // Credit transactions table (for tracking all add/subtract operations)
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['add', 'subtract'])->comment('add = load credits, subtract = send/receive SMS');
            $table->integer('amount')->comment('Number of credits');
            $table->integer('balance_before')->comment('Balance before transaction');
            $table->integer('balance_after')->comment('Balance after transaction');
            $table->string('transaction_type')->comment('load, sms_sent, sms_received');
            $table->text('description')->nullable();
            $table->foreignId('sms_inbox_id')->nullable()->constrained('sms_inboxes')->onDelete('set null')
                ->comment('Reference to SMS if transaction is from sending/receiving');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null')
                ->comment('User who performed the action (for manual credit loading)');
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['transaction_type']);
        });

        // Initialize with 0 balance
        DB::table('sms_credits')->insert([
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('sms_credits');
    }
};
