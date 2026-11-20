<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to admins when one account gets locked out several times in a day. */
class AccountLockedOut extends Notification implements ShouldQueue
{
    use Queueable;

    private bool $databaseOnly = false;

    public function __construct(
        public string $login,
        public ?User $user,
        public int $count,
        public ?string $ip,
    ) {}

    /** Copy that only goes to the bell (the email already went to the alert addresses). */
    public function onlyDatabase(bool $only = true): static
    {
        $copy = clone $this;
        $copy->databaseOnly = $only;

        return $copy;
    }

    public function via(object $notifiable): array
    {
        if ($this->databaseOnly) {
            return ['database'];
        }

        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Repeated lockouts on :login', ['login' => $this->login]))
            ->line(__('The account ":login" was locked out :count times today after too many failed sign-in attempts.', ['login' => $this->login, 'count' => $this->count]))
            ->line(__('Last attempt from IP address: :ip', ['ip' => $this->ip ?: '—']))
            ->action(__('Open the sign-in log'), route('sign-ins.index', ['login' => $this->login]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => __('Repeated lockouts on :login', ['login' => $this->login]),
            'body' => __(':count lockouts today. Last IP: :ip', ['count' => $this->count, 'ip' => $this->ip ?: '—']),
            'url' => route('sign-ins.index', ['login' => $this->login]),
            'icon' => 'fa-user-lock',
            'color' => 'red',
        ];
    }
}
