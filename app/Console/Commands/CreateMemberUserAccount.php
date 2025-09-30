<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateMemberUserAccount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'member:create-user-account {member_id} {--password=password123}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a user account for an existing member';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $memberId = $this->argument('member_id');
        $password = $this->option('password');

        // Find the member
        $member = Member::find($memberId);
        
        if (!$member) {
            $this->error("Member with ID {$memberId} not found.");
            return 1;
        }

        // Check if member already has a user account
        if ($member->user) {
            $this->error("Member {$member->name} already has a user account.");
            return 1;
        }

        // Create user account
        $user = User::create([
            'name' => $member->name,
            'email' => $member->email,
            'password' => Hash::make($password),
            'member_id' => $member->id,
        ]);

        $this->info("User account created successfully for member {$member->name}!");
        $this->line("Email: {$user->email}");
        $this->line("Password: {$password}");
        $this->line("Member ID: {$member->id}");

        return 0;
    }
}
