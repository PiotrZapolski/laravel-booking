<?php

namespace Zapol\Booking\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a booking has been successfully created on Google Calendar
 * and confirmation emails queued. Host applications listen for this to fire
 * downstream side effects (analytics, CRM webhooks, etc.).
 *
 * Listeners receive the full payload returned to the widget (token, event_id,
 * start, end, meet_link, fields, …) plus the event-type definition.
 */
class BookingCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public array $payload,
        public array $eventType,
    ) {}
}
