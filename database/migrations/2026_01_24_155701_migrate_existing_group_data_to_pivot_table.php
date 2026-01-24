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
        // Migrate existing group_id data to the pivot table
        // Only migrate members that have a group_id set
        $members = \DB::table('members')
            ->whereNotNull('group_id')
            ->select('id', 'group_id')
            ->get();
        
        foreach ($members as $member) {
            // Insert into pivot table, avoiding duplicates
            \DB::table('group_member')->insertOrIgnore([
                'group_id' => $member->group_id,
                'member_id' => $member->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Clear the pivot table
        \DB::table('group_member')->truncate();
    }
};
