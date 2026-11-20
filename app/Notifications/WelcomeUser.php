<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when an admin creates an account: a link to choose a password. */
class WelcomeUser extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email, 'welcome' => 1]);
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject(__('Your :app account', ['app' => setting('app_name')]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('An account was created for you on :app.', ['app' => setting('app_name')]))
            ->line(__('Your sign-in email is :email.', ['email' => $notifiable->email]))
            ->action(__('Choose my password'), $url)
            ->line(__('This link expires in :count minutes. After that, use "Forgot password" on the sign-in page.', ['count' => $minutes]));
    }
}
