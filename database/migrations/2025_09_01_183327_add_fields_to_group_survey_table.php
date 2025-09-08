<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_survey', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->after('automated');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->boolean('was_dispatched')->default(false)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_survey', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at', 'was_dispatched']);
        });
    }
};
