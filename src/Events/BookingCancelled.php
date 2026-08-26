<?php

namespace Zapol\Booking\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a booking has been removed from the calendar and the
 * cancellation emails have been queued.
 *
 * Listeners receive the payload sent to the cancellation mails (event_id,
 * event_type, attendee, the original start / end, ...) plus the event-type
 * definition, which is an empty array when the event type no longer exists.
 */
class BookingCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $payload,
        public array $eventType,
    ) {}
}
