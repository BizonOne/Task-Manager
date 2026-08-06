<?php

namespace App\Notifications\Concerns;

use App\Support\Notifications\ChatMessage;
use App\Support\Notifications\Delivery;

/**
 * Sends a notification everywhere its recipient has asked to be told.
 *
 * A trait rather than a line in each notification, because the line that gets
 * forgotten is the one somebody was waiting on. Anything using this picks up
 * new channels the moment they exist, with nothing to edit.
 */
trait GoesWherePeopleAre
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return Delivery::via($notifiable, $this);
    }

    /**
     * The short form, for chat.
     *
     * Built from the payload the notification already stores for the bell, so
     * a notification gets a decent chat message for free. Override it where
     * the wording deserves better.
     */
    public function toChat(object $notifiable): ChatMessage
    {
        return ChatMessage::fromPayload($this->toArray($notifiable));
    }
}
