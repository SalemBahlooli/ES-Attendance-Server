<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/** The "forgot password" email, translated and branded. */
class ResetPassword extends BaseResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject(__('Reset your password'))
            ->line(__('You are receiving this email because we received a password reset request for your account.'))
            ->action(__('Reset password'), $url)
            ->line(__('This link expires in :count minutes.', ['count' => $minutes]))
            ->line(__('If you did not ask to reset your password, you can ignore this email.'));
    }
}
