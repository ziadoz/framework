<?php

use Illuminate\Notifications\PendingNotification;

if (! function_exists('notify')) {
    /**
     * Send the given notification to the given notifiable entities.
     *
     * @param  \Illuminate\Support\Collection|mixed  $notifiables
     * @param  mixed  $notification
     * @return PendingNotification
     */
    function notify($notifiables, $notification)
    {
        return new PendingNotification($notifiables, $notification);
    }
}
