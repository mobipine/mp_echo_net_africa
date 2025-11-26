<?php

namespace App\Notifications;

use App\Models\SMSInbox;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;

class CustomResetPasswordNotification extends Notification
{
    public $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        
        $url = Filament::getResetPasswordUrl($this->token, $notifiable);

        Log::info($url);
        // SMSInbox::create([
        //     "message" => "Please click on the link below to reset your password \n" . $url,
        //     'phone_number' => '0701071662',
        //     'status' => 'pending',
        //     'channel' => 'sms',
        //     'member_id' => 1
        // ]);
        return (new MailMessage)
            ->subject('Reset Your Password')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
