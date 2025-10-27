<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Database\Seeder;

class GroupsAndMembersTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Creating test groups and members...');
        
        // Group 1: Imani Group
        $imani = Group::create([
            'name' => 'Imani Women Group',
            'email' => 'imani@forumkenya.com',
            'phone_number' => '+254700111222',
            'county' => 'Nairobi',
            'sub_county' => 'Westlands',
            'address' => 'Westlands Plaza',
            'township' => 'Westlands',
            'ward' => 'Parklands',
            'formation_date' => now()->subMonths(6),
            'registration_number' => 'REG-2024-001',
        ]);
        $this->command->info("✓ Created: {$imani->name} (ID: {$imani->id})");
        
        // Members for Imani Group
        $imaniMembers = [
            ['name' => 'Mary Wanjiku', 'email' => 'mary@test.com', 'phone' => '+254700111001', 'national_id' => '12345001', 'gender' => 'Female'],
            ['name' => 'Jane Akinyi', 'email' => 'jane@test.com', 'phone' => '+254700111002', 'national_id' => '12345002', 'gender' => 'Female'],
            ['name' => 'Grace Muthoni', 'email' => 'grace@test.com', 'phone' => '+254700111003', 'national_id' => '12345003', 'gender' => 'Female'],
            ['name' => 'Lucy Njeri', 'email' => 'lucy@test.com', 'phone' => '+254700111004', 'national_id' => '12345004', 'gender' => 'Female'],
            ['name' => 'Faith Wambui', 'email' => 'faith@test.com', 'phone' => '+254700111005', 'national_id' => '12345005', 'gender' => 'Female'],
        ];
        
        foreach ($imaniMembers as $memberData) {
            Member::create(array_merge($memberData, [
                'group_id' => $imani->id,
                'dob' => now()->subYears(30),
                'marital_status' => 'Married',
                'is_active' => true,
                'member_since' => now()->subMonths(6),
                'membership_status' => 'active',
            ]));
        }
        $this->command->info("  ✓ Created " . count($imaniMembers) . " members for Imani Group");
        
        // Group 2: Jamii Group
        $jamii = Group::create([
            'name' => 'Jamii Traders Group',
            'email' => 'jamii@forumkenya.com',
            'phone_number' => '+254700222333',
            'county' => 'Kiambu',
            'sub_county' => 'Kiambu Town',
            'address' => 'Kiambu Market',
            'township' => 'Kiambu',
            'ward' => 'Township',
            'formation_date' => now()->subMonths(12),
            'registration_number' => 'REG-2023-002',
        ]);
        $this->command->info("✓ Created: {$jamii->name} (ID: {$jamii->id})");
        
        // Members for Jamii Group
        $jamiiMembers = [
            ['name' => 'John Kamau', 'email' => 'john@test.com', 'phone' => '+254700222001', 'national_id' => '12345011', 'gender' => 'Male'],
            ['name' => 'Peter Ochieng', 'email' => 'peter@test.com', 'phone' => '+254700222002', 'national_id' => '12345012', 'gender' => 'Male'],
            ['name' => 'David Kipchoge', 'email' => 'david@test.com', 'phone' => '+254700222003', 'national_id' => '12345013', 'gender' => 'Male'],
            ['name' => 'Sarah Wanjiru', 'email' => 'sarah@test.com', 'phone' => '+254700222004', 'national_id' => '12345014', 'gender' => 'Female'],
            ['name' => 'Elizabeth Auma', 'email' => 'elizabeth@test.com', 'phone' => '+254700222005', 'national_id' => '12345015', 'gender' => 'Female'],
            ['name' => 'James Otieno', 'email' => 'james@test.com', 'phone' => '+254700222006', 'national_id' => '12345016', 'gender' => 'Male'],
        ];
        
        foreach ($jamiiMembers as $memberData) {
            Member::create(array_merge($memberData, [
                'group_id' => $jamii->id,
                'dob' => now()->subYears(35),
                'marital_status' => 'Married',
                'is_active' => true,
                'member_since' => now()->subMonths(12),
                'membership_status' => 'active',
            ]));
        }
        $this->command->info("  ✓ Created " . count($jamiiMembers) . " members for Jamii Group");
        
        // Group 3: Tumaini Group
        $tumaini = Group::create([
            'name' => 'Tumaini Youth Group',
            'email' => 'tumaini@forumkenya.com',
            'phone_number' => '+254700333444',
            'county' => 'Nakuru',
            'sub_county' => 'Nakuru East',
            'address' => 'Nakuru Town',
            'township' => 'Nakuru',
            'ward' => 'Menengai',
            'formation_date' => now()->subMonths(3),
            'registration_number' => 'REG-2024-003',
        ]);
        $this->command->info("✓ Created: {$tumaini->name} (ID: {$tumaini->id})");
        
        // Members for Tumaini Group
        $tumainiMembers = [
            ['name' => 'Kevin Mwangi', 'email' => 'kevin@test.com', 'phone' => '+254700333001', 'national_id' => '12345021', 'gender' => 'Male'],
            ['name' => 'Dennis Mugo', 'email' => 'dennis@test.com', 'phone' => '+254700333002', 'national_id' => '12345022', 'gender' => 'Male'],
            ['name' => 'Ann Nyambura', 'email' => 'ann@test.com', 'phone' => '+254700333003', 'national_id' => '12345023', 'gender' => 'Female'],
            ['name' => 'Michael Omondi', 'email' => 'michael@test.com', 'phone' => '+254700333004', 'national_id' => '12345024', 'gender' => 'Male'],
        ];
        
        foreach ($tumainiMembers as $memberData) {
            Member::create(array_merge($memberData, [
                'group_id' => $tumaini->id,
                'dob' => now()->subYears(25),
                'marital_status' => 'Single',
                'is_active' => true,
                'member_since' => now()->subMonths(3),
                'membership_status' => 'active',
            ]));
        }
        $this->command->info("  ✓ Created " . count($tumainiMembers) . " members for Tumaini Group");
        
        $this->command->info('');
        $this->command->info('Summary:');
        $this->command->info('  • Total Groups: 3');
        $this->command->info('  • Total Members: ' . Member::count());
        $this->command->info('  • Total Group Accounts: ' . \App\Models\GroupAccount::count());
        $this->command->info('');
        $this->command->info('Note: Group accounts were automatically created by GroupObserver');
    }
}

