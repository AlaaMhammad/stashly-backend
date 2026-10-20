<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Password-reset email, sent through the queue (contract section 5). The link
 * points at the /reset-password web page (see AppServiceProvider) and the
 * framework mail states the 60-minute expiry from config/auth.php.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
