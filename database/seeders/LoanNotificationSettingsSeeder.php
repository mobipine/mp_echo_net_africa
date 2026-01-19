<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class LoanNotificationSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'key' => 'loan_notifications.enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'loan_notifications',
                'label' => 'Enable Loan Notifications',
                'description' => 'Master switch for all loan-related email notifications',
            ],
            [
                'key' => 'loan_notifications.admin_email',
                'value' => 'royimwangi@gmail.com',
                'type' => 'string',
                'group' => 'loan_notifications',
                'label' => 'Admin Email Address',
                'description' => 'Email address to receive admin notifications for loan repayments',
            ],
            [
                'key' => 'loan_notifications.member_notifications',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'loan_notifications',
                'label' => 'Member Notifications',
                'description' => 'Send email notifications to loan members when repayments are recorded',
            ],
            [
                'key' => 'loan_notifications.admin_notifications',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'loan_notifications',
                'label' => 'Admin Notifications',
                'description' => 'Send email notifications to administrators when repayments are recorded',
            ],
            [
                'key' => 'loan_notifications.queue',
                'value' => 'emails',
                'type' => 'string',
                'group' => 'loan_notifications',
                'label' => 'Notification Queue',
                'description' => 'Queue name for processing loan notification jobs',
            ],
            [
                'key' => 'loan_repayment.email_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'loan_notifications',
                'label' => 'Email Notifications for Repayments',
                'description' => 'Enable email notifications specifically for loan repayments',
            ],
            [
                'key' => 'loan_repayment.sms_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'loan_notifications',
                'label' => 'SMS Notifications for Repayments',
                'description' => 'Enable SMS notifications for loan repayments (future feature)',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
