<?php

namespace Illuminate\Notifications;

use Illuminate\Support\Collection;

class PendingNotification
{
    /**
     * Indicates that the notification should be sent immediately.
     *
     * @var bool
     */
    protected bool $immediately = false;

    /**
     * Indicates that the notification should be sent after the response is sent to the browser.
     *
     * @var bool
     */
    protected bool $afterResponse = false;

    /**
     * Indicates the channels that the notification should be sent on when being sent immediately or after the response is sent to the browser.
     *
     * @var array
     */
    protected array $onChannels = [];

    /**
     * Create a new pending notification.
     *
     * @param  \Illuminate\Support\Collection|mixed  $notifiables
     * @param  mixed  $notification
     */
    public function __construct(protected $notifiables, protected $notification)
    {
    }

    /**
     * Indicate the notification should be sent immediately.
     *
     * @return self
     */
    public function immediately(): self
    {
        $this->immediately = true;

        return $this;
    }

    /**
     * Indicate that the notification should be sent after the response is sent to the browser.
     *
     * @return $this
     */
    public function afterResponse()
    {
        $this->afterResponse = true;

        return $this;
    }

    /**
     * Indicate the channels that the notification should be sent on when being sent immediately or after the response is sent to the browser.
     *
     * @return $this
     */
    public function onChannels(array $channels): self
    {
        $this->onChannels = $channels;

        return $this;
    }

    /**
     * Handle the object's destruction.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->afterResponse) {
            app()->terminating(function () {
                app(ChannelManager::class)->sendNow($this->notifiables, $this->notification, $this->onChannels);
            });
        } elseif ($this->immediately) {
            app(ChannelManager::class)->sendNow($this->notifiables, $this->notification, $this->onChannels);
        } else {
            app(ChannelManager::class)->send($this->notifiables, $this->notification);
        }
    }
}
