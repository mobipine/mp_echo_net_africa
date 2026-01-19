<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Loan Notification Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for loan-related email notifications
    | These settings are now managed through the database via the Settings model
    | and can be configured through the Filament admin interface.
    |
    */

    // Default fallback values - actual values are stored in database
    'admin_email' => 'admin@example.com',
    'enabled' => true,
    'member_notifications' => true,
    'admin_notifications' => true,
    'queue' => 'default',
    'email_enabled' => true,
    'sms_enabled' => false,
];