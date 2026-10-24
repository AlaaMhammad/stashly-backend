<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password-reset e-mail carrying the 6-digit code (DEC-040). Deliberately NOT queued: on shared
 * hosting (Hostinger) no queue worker runs, so a queued e-mail would never arrive.
 * PasswordResetCodeService::send sends it immediately, after the HTTP response.
 */
class PasswordResetCodeNotification extends Notification
{
    public function __construct(public readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = [
            'code' => $this->code,
            'minutes' => (int) config('stashly.password_reset.code_ttl_minutes'),
            'appName' => config('app.name'),
            'name' => $notifiable->display_name ?? null,
        ];

        return (new MailMessage)
            ->subject($this->code.' is your '.config('app.name').' password reset code')
            ->view(['emails.auth.password-reset-otp', 'emails.auth.password-reset-otp-text'], $data);
    }
}
