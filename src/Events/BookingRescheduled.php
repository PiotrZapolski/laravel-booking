<?php

namespace Zapol\Booking\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a booking has been moved to a new time on the calendar and
 * the reschedule emails have been queued.
 *
 * Listeners receive the payload returned to the widget (token, event_id, the
 * new start / end, meet_link, ...) plus the event-type definition.
 */
class BookingRescheduled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $payload,
        public array $eventType,
    ) {}
}
