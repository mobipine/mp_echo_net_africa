<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use function Livewire\after;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('local_implementing_partner_id')->nullable()->constrained()->onDelete('cascade')->after('phone_number');
            $table->foreignId('county_ENA_staff_id')->nullable()->constrained()->onDelete('cascade')->after('lip_id');
            $table->string('ward')->after('sub_county')->nullable();
            $table->string('group_certificate')->after('township')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['local_implementing_partner_id']);
            $table->dropColumn('local_implementing_partner_id');
            $table->dropForeign(['county_ENA_staff_id']);
            $table->dropColumn('county_ENA_staff_id');
            $table->dropColumn('ward');
            $table->dropColumn('group_certificate');
        });
    }
};
