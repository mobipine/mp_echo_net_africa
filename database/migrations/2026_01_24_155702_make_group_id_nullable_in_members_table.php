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
        Schema::table('members', function (Blueprint $table) {
            // Make group_id nullable to support backward compatibility
            // We'll keep it for now but it will be deprecated
            $table->foreignId('group_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Restore non-nullable constraint
            // Note: This may fail if there are null values
            $table->foreignId('group_id')->nullable(false)->change();
        });
    }
};
