<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('subject')->nullable();
            $table->text('body_email')->nullable();
            $table->text('body_sms')->nullable();
            $table->text('body_whatsapp')->nullable();
            $table->json('channels'); // Stores enabled channels: ['email', 'sms', 'whatsapp']
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};