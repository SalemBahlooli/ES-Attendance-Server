<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A ready-made notification for your modules: shows in the bell and,
 * optionally, goes by email.
 *
 *   $user->notify(new SimpleNotification(
 *       title: 'Your request was approved',
 *       body:  'Request #42 was approved by Sara.',
 *       url:   route('requests.show', 42),
 *       icon:  'fa-circle-check',
 *       color: 'green',
 *       mail:  true,
 *   ));
 *
 *   Notification::send(User::withPermission('requests.approve'), new SimpleNotification(...));
 */
class SimpleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $url = null,
        public string $icon = 'fa-bell',
        public string $color = 'blue',
        public bool $mail = false,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->mail && ! empty($notifiable->email) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title)->line($this->body ?? '');

        return $this->url ? $mail->action(__('Open'), $this->url) : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }
}
