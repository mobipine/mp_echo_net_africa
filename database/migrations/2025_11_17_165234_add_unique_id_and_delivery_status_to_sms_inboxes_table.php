<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->string('unique_id')->nullable()->after('message');
            $table->string('delivery_status')->default('pending')->after('unique_id');
            $table->string('delivery_status_desc')->nullable()->after('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('sms_inboxes', function (Blueprint $table) {
            $table->dropColumn(['unique_id', 'delivery_status']);
        });
    }
};
