<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_transport_logs', function (Blueprint $table) {
            $table->id();
            $table->string('transport');
            $table->string('direction');
            $table->foreignId('sms_inbox_id')->nullable()->constrained('sms_inboxes')->nullOnDelete();
            $table->foreignId('survey_progress_id')->nullable()->constrained('survey_progress')->nullOnDelete();
            $table->string('phone_number')->nullable();
            $table->text('message')->nullable();
            $table->string('provider_message_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_transport_logs');
    }
};
