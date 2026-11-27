<?php

namespace Zapol\Booking\Listeners;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Throwable;
use Zapol\Booking\Events\BookingCancelled;
use Zapol\Booking\Events\BookingCreated;
use Zapol\Booking\Events\BookingRescheduled;

/**
 * Mirrors the booking lifecycle into the optional `booking_bookings` table
 * (migration tag `booking-migrations`). Registered by the service provider
 * only when `booking.persist_bookings` is true, so the default install never
 * touches the database.
 *
 * The calendar stays the source of truth: this table is an audit log. Every
 * failure (no connection, missing table, bad row) is reported and swallowed,
 * because by the time the event fires the calendar event exists and the
 * emails are out.
 */
class PersistBooking
{
    public const TABLE = 'booking_bookings';

    /**
     * @param BookingCreated|BookingRescheduled|BookingCancelled $event
     */
    public function handle(object $event): void
    {
        if (!config('booking.persist_bookings')) {
            return;
        }

        try {
            $db = app('db')->connection();

            if (!$db->getSchemaBuilder()->hasTable(self::TABLE)) {
                Log::warning('Booking package: persist_bookings is on but the [' . self::TABLE . '] table is missing. Run: php artisan vendor:publish --tag=booking-migrations && php artisan migrate');
                return;
            }

            if ($event instanceof BookingCreated) {
                $this->created($db, $event->payload);
            } elseif ($event instanceof BookingRescheduled) {
                $this->rescheduled($db, $event->payload);
            } elseif ($event instanceof BookingCancelled) {
                $this->cancelled($db, $event->payload);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @param array<string,mixed> $payload */
    private function created(ConnectionInterface $db, array $payload): void
    {
        $db->table(self::TABLE)->insert($this->row($payload) + [
            'status'     => 'confirmed',
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function rescheduled(ConnectionInterface $db, array $payload): void
    {
        $updated = $db->table(self::TABLE)
            ->where('google_event_id', (string) $payload['event_id'])
            ->update([
                'starts_at'  => $this->dateTime($payload['start']),
                'ends_at'    => $this->dateTime($payload['end']),
                'status'     => 'confirmed',
                'updated_at' => $this->now(),
            ]);

        // A booking made before persistence was switched on has no row yet;
        // the reschedule payload carries enough to start its record here.
        if ($updated === 0 && !$db->table(self::TABLE)->where('google_event_id', (string) $payload['event_id'])->exists()) {
            $this->created($db, $payload);
        }
    }

    /** @param array<string,mixed> $payload */
    private function cancelled(ConnectionInterface $db, array $payload): void
    {
        $db->table(self::TABLE)
            ->where('google_event_id', (string) $payload['event_id'])
            ->update([
                'status'     => 'cancelled',
                'updated_at' => $this->now(),
            ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function row(array $payload): array
    {
        $fields = (array) ($payload['fields'] ?? []);
        $name = $fields['name'] ?? null;

        return [
            'event_type'      => (string) $payload['event_type'],
            'attendee_email'  => (string) ($fields['email'] ?? $payload['attendee'] ?? ''),
            'attendee_name'   => is_string($name) && $name !== '' ? $name : null,
            // The calendar event id of whichever driver is active, not only Google.
            'google_event_id' => (string) $payload['event_id'],
            'starts_at'       => $this->dateTime($payload['start']),
            'ends_at'         => $this->dateTime($payload['end']),
            'fields'          => $fields ? json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'meet_link'       => $payload['meet_link'] ?? null,
        ];
    }

    /**
     * Stored the way Eloquent stores datetimes: in the app timezone, without
     * an offset, so a host model with a `datetime` cast reads it back right.
     */
    private function dateTime(string $iso): string
    {
        return CarbonImmutable::parse($iso)
            ->setTimezone((string) config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function now(): string
    {
        return CarbonImmutable::now((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }
}
