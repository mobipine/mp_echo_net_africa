<?php

namespace App\Filament\Auth;

use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class RequestPasswordResetPage extends RequestPasswordReset
{
    public function send(): void
    {
        $state = $this->form->getState();
        $email = $state['email'] ?? null;

        $status = Password::broker()->sendResetLink(['email' => $email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        $this->notify('A password reset link has been queued for sending.');
    }
}
