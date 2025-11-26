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
            $table->foreignId('county_id')
                ->nullable()   // keep nullable so existing members don’t break
                ->constrained('counties')
                ->nullOnDelete()
                ->cascadeOnUpdate()
                ->after('gender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropForeign(['county_id']);
            $table->dropColumn('county_id');
        });
    }
};
