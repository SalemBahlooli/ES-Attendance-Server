<?php

namespace App\Notifications;

use App\Models\SignIn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your account was just signed in from a new device." */
class NewDeviceSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SignIn $signIn) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $agent = $this->signIn->agent();

        return (new MailMessage)
            ->subject(__('New sign-in to your account'))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('Your account was just signed in from a device we have not seen before.'))
            ->line(__('Device: :device', ['device' => $agent['label']]))
            ->line(__('IP address: :ip', ['ip' => $this->signIn->ip_address]))
            ->line(__('Time: :time', ['time' => format_datetime($this->signIn->created_at)]))
            ->line(__('If this was you, you can ignore this email. If not, change your password and sign out the device.'))
            ->action(__('Review my devices'), route('account.security'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('New sign-in from :device', ['device' => $this->signIn->agent()['label']]),
            'body' => __('IP address: :ip', ['ip' => $this->signIn->ip_address]),
            'url' => route('account.security'),
            'icon' => 'fa-laptop',
            'color' => 'amber',
        ];
    }
}
