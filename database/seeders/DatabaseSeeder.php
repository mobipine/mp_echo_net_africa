<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // $this->call([
        //     UserSeeder::class,
        // ]);

        //loop through all members and create a unique account number for each member
        //the account number should be in the format ACC-0001, ACC-0002, etc.
        $members = \App\Models\Member::all();
        foreach ($members as $index => $member) {
            $member->account_number = 'ACC-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT);
            $member->save();
        }
    }
}
