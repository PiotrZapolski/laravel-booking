<?php

namespace Zapol\Booking\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Throwable;
use Zapol\Booking\Events\BookingCancelled;
use Zapol\Booking\Events\BookingCreated;
use Zapol\Booking\Events\BookingRescheduled;

/**
 * Posts a one-line summary of every booking lifecycle event to a Slack
 * incoming webhook. Registered by the service provider only when
 * `booking.notifications.slack_webhook_url` is set.
 *
 * Failures are reported and swallowed: a dead webhook must never break a
 * booking.
 */
class NotifySlack
{
    public function __construct(private Factory $http) {}

    /**
     * @param BookingCreated|BookingRescheduled|BookingCancelled $event
     */
    public function handle(object $event): void
    {
        $url = config('booking.notifications.slack_webhook_url');
        if (!$url) {
            return;
        }

        try {
            $text = $this->messageFor($event);
            if ($text === null) {
                return;
            }

            $this->http->timeout(15)->post($url, ['text' => $text]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function messageFor(object $event): ?string
    {
        if ($event instanceof BookingCreated) {
            $prefix = 'New booking';
        } elseif ($event instanceof BookingRescheduled) {
            $prefix = 'Booking rescheduled';
        } elseif ($event instanceof BookingCancelled) {
            $prefix = 'Booking cancelled';
        } else {
            return null;
        }

        $payload = $event->payload;
        $type = $event->eventType;

        $title = $type['title'] ?? ($payload['event_type'] ?? 'Booking');
        $name = $payload['fields']['name'] ?? null;
        $email = $payload['fields']['email'] ?? ($payload['attendee'] ?? null);

        $who = trim((string) $name);
        if ($email) {
            $who = $who !== '' ? $who . ' <' . $email . '>' : '<' . $email . '>';
        }

        $line = $prefix . ': ' . $title;
        if ($who !== '') {
            $line .= ' with ' . $who;
        }

        $when = $this->formatWhen($payload['start'] ?? null);
        if ($when !== null) {
            $line .= ' on ' . $when;
        }

        if (!empty($payload['meet_link']) && !$event instanceof BookingCancelled) {
            $line .= "\n" . $payload['meet_link'];
        }

        return $line;
    }

    private function formatWhen($start): ?string
    {
        if (!is_string($start) || $start === '') {
            return null;
        }

        $tz = config('booking.organizer.timezone', 'UTC');

        try {
            return CarbonImmutable::parse($start)->setTimezone($tz)->format('D, d M Y H:i') . ' (' . $tz . ')';
        } catch (Throwable $e) {
            return null;
        }
    }
}
