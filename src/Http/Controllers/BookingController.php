<?php

namespace Zapol\Booking\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Throwable;
use Zapol\Booking\Conference\ConferenceResolver;
use Zapol\Booking\Contracts\CalendarProvider;
use Zapol\Booking\Events\BookingCancelled as BookingCancelledEvent;
use Zapol\Booking\Events\BookingCreated;
use Zapol\Booking\Events\BookingRescheduled as BookingRescheduledEvent;
use Zapol\Booking\Mail\BookingCancelled;
use Zapol\Booking\Mail\BookingConfirmation;
use Zapol\Booking\Mail\BookingConfirmationOrganizer;
use Zapol\Booking\Mail\BookingRescheduled;
use Zapol\Booking\Services\BookingTokenSigner;
use Zapol\Booking\Support\BookingMailer;
use Zapol\Booking\Support\EventTypeResolver;
use Zapol\Booking\Support\LocationResolver;

class BookingController extends Controller
{
    public function store(
        Request $request,
        CalendarProvider $calendar,
        BookingTokenSigner $signer,
        BookingMailer $mailer,
    ): JsonResponse {
        $data = $request->validate([
            'event_type' => ['required', 'string'],
            'slot'       => ['required', 'string'],
            'fields'     => ['required', 'array'],
            'timezone'   => ['nullable', 'string'],
            'lang'       => ['nullable', 'string', 'max:8'],
            // Honeypot: must be empty
            'hp_company' => ['nullable', 'string', 'max:0'],
        ]);

        $type = EventTypeResolver::find($data['event_type']);
        if (!$type) {
            return response()->json(['error' => 'event_type_not_found'], 404);
        }

        $error = $this->validateFormFields($type['form_fields'] ?? [], $data['fields']);
        if ($error) {
            return response()->json(['error' => 'invalid_fields', 'message' => $error], 422);
        }

        $organizerTz = config('booking.organizer.timezone', 'UTC');
        try {
            $start = CarbonImmutable::parse($data['slot'])->setTimezone($organizerTz);
        } catch (Throwable $e) {
            return response()->json(['error' => 'invalid_slot'], 422);
        }
        $duration = (int)($type['duration_minutes'] ?? 30);
        $end = $start->addMinutes($duration);

        // Race-condition guard: re-query freebusy for just this slot.
        $busy = $calendar->freeBusy($start->subMinutes(1), $end->addMinutes(1));
        foreach ($busy as $b) {
            if ($start->lessThan($b['end']) && $end->greaterThan($b['start'])) {
                return response()->json(['error' => 'slot_no_longer_available'], 409);
            }
        }

        $attendeeEmail = (string) ($data['fields']['email'] ?? '');
        $attendeeName  = (string) ($data['fields']['name'] ?? '');
        $summary = $type['title'] ?? $data['event_type'];
        $description = $this->buildDescription($type['form_fields'] ?? [], $data['fields']);

        // A standalone conference (Zoom) has to exist before the calendar
        // event, because its join URL becomes the event location.
        $conference = ConferenceResolver::for($type['location'] ?? null);
        $meeting = null;
        if ($conference) {
            try {
                $meeting = $conference->create([
                    'summary'  => $summary,
                    'start'    => $start,
                    'end'      => $end,
                    'timezone' => $organizerTz,
                    'attendee' => ['email' => $attendeeEmail, 'name' => $attendeeName],
                ]);
            } catch (Throwable $e) {
                report($e);
                return response()->json(['error' => 'conference_create_failed', 'message' => $e->getMessage()], 502);
            }
        }

        $nativeConference = $this->nativeConferenceFor($type, $calendar);
        $joinUrl = $meeting['join_url'] ?? null;

        try {
            $created = $calendar->createEvent([
                'summary'              => $summary,
                'description'          => $description,
                'start'                => $start,
                'end'                  => $end,
                'timezone'             => $organizerTz,
                'attendee'             => ['email' => $attendeeEmail, 'name' => $attendeeName],
                'additional_attendees' => $type['additional_attendees'] ?? [],
                'conference'           => $nativeConference,
                'location'             => LocationResolver::calendarLocation($type, $joinUrl),
            ]);
        } catch (Throwable $e) {
            report($e);
            // Do not leave an orphaned conference behind.
            if ($conference && $meeting) {
                $this->quietly(fn () => $conference->delete((string) $meeting['id']));
            }
            return response()->json(['error' => 'calendar_create_failed', 'message' => $e->getMessage()], 502);
        }

        $tokenBody = [
            'gid' => $created['id'],
            'et'  => $data['event_type'],
            'em'  => $attendeeEmail,
        ];
        if (!empty($data['lang'])) {
            $tokenBody['lang'] = $data['lang'];
        }
        if ($meeting) {
            $tokenBody['cm'] = (string) $meeting['id'];
        }
        $token = $signer->sign($tokenBody, 86400 * (int) config('booking.token_ttl_days', 60));

        $rescheduleUrl = URL::route('booking.reschedule', ['token' => $token]);
        $location = LocationResolver::describe($type, $joinUrl);

        $payload = [
            'token'          => $token,
            'event_id'       => $created['id'],
            'event_type'     => $data['event_type'],
            'start'          => $start->toIso8601String(),
            'end'            => $end->toIso8601String(),
            'meet_link'      => LocationResolver::meetLink($type, $created['meet_link'] ?? null, $joinUrl),
            'html_link'      => $created['html_link'] ?? null,
            'meet_provider'  => $location['meet_provider'],
            'location_label' => $location['location_label'],
            'location_text'  => $location['location_text'],
            'reschedule_url' => $rescheduleUrl,
            'fields'         => $data['fields'],
            'lang'           => $data['lang'] ?? null,
        ];

        $mailer->send(new BookingConfirmation($payload, $type), $attendeeEmail);
        if (config('booking.mail.notify_organizer', true) && config('booking.organizer.email')) {
            $mailer->send(new BookingConfirmationOrganizer($payload, $type), config('booking.organizer.email'));
        }

        try {
            event(new BookingCreated($payload, $type));
        } catch (Throwable $e) {
            // Listener errors must not break the booking itself.
            report($e);
        }

        return response()->json($payload, 201);
    }

    public function show(string $token, BookingTokenSigner $signer, CalendarProvider $calendar): JsonResponse
    {
        try {
            $payload = $signer->verify($token);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_token', 'message' => $e->getMessage()], 401);
        }

        $event = $calendar->getEvent($payload['gid']);
        if (!$event) {
            return response()->json(['error' => 'event_not_found'], 404);
        }

        $type = EventTypeResolver::find($payload['et']) ?? [];

        return response()->json([
            'event_type'     => $payload['et'],
            'attendee'       => $payload['em'],
            'start'          => $event['start'],
            'end'            => $event['end'],
            'summary'        => $event['summary'],
            'meet_link'      => LocationResolver::meetLink($type, $event['meet_link'] ?? null),
            'meet_provider'  => LocationResolver::meetProvider($type),
            'location_label' => LocationResolver::label($type),
        ]);
    }

    public function reschedule(
        Request $request,
        string $token,
        BookingTokenSigner $signer,
        CalendarProvider $calendar,
        BookingMailer $mailer,
    ): JsonResponse {
        try {
            $payload = $signer->verify($token);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $data = $request->validate(['slot' => ['required', 'string']]);

        $type = EventTypeResolver::find($payload['et']);
        if (!$type) {
            return response()->json(['error' => 'event_type_not_found'], 404);
        }

        $tz = config('booking.organizer.timezone', 'UTC');
        try {
            $start = CarbonImmutable::parse($data['slot'])->setTimezone($tz);
        } catch (Throwable $e) {
            return response()->json(['error' => 'invalid_slot'], 422);
        }
        $duration = (int) ($type['duration_minutes'] ?? 30);
        $end = $start->addMinutes($duration);

        // Pull the existing event first so we can carry over rich detail
        // (join link, attendee display name) into the reschedule mail without
        // a second round-trip after the patch.
        $existingEvent = $calendar->getEvent($payload['gid']);
        $meetLink = $existingEvent['meet_link'] ?? null;
        $attendeeName = $this->attendeeNameFrom($existingEvent, $payload['em']);

        try {
            $calendar->updateEventTime($payload['gid'], $start, $end, $tz);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['error' => 'calendar_patch_failed', 'message' => $e->getMessage()], 502);
        }

        // Best effort: a conference that refuses to move must not undo a
        // reschedule the calendar has already accepted.
        $conference = ConferenceResolver::for($type['location'] ?? null);
        if ($conference && !empty($payload['cm'])) {
            $this->quietly(fn () => $conference->reschedule((string) $payload['cm'], $start, $end, $tz));
        }

        $rescheduleUrl = URL::route('booking.reschedule', ['token' => $token]);
        $location = LocationResolver::describe($type);

        $msg = [
            'token'          => $token,
            'event_id'       => $payload['gid'],
            'event_type'     => $payload['et'],
            'start'          => $start->toIso8601String(),
            'end'            => $end->toIso8601String(),
            'meet_link'      => LocationResolver::meetLink($type, $meetLink),
            'meet_provider'  => $location['meet_provider'],
            'location_label' => $location['location_label'],
            'location_text'  => $location['location_text'],
            'reschedule_url' => $rescheduleUrl,
            'attendee'       => $payload['em'],
            'lang'           => $payload['lang'] ?? null,
            'fields'         => array_filter([
                'email' => $payload['em'],
                'name'  => $attendeeName,
            ]),
        ];
        $mailer->send(new BookingRescheduled($msg, $type), $payload['em']);
        if (config('booking.mail.notify_organizer', true) && config('booking.organizer.email')) {
            $mailer->send(new BookingRescheduled($msg, $type), config('booking.organizer.email'));
        }

        try {
            event(new BookingRescheduledEvent($msg, $type));
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true] + $msg);
    }

    public function cancel(
        string $token,
        BookingTokenSigner $signer,
        CalendarProvider $calendar,
        BookingMailer $mailer,
    ): JsonResponse {
        try {
            $payload = $signer->verify($token);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        // Capture the event details BEFORE we delete it so the cancellation
        // mail + CANCEL ICS can carry the original datetime.
        $existing = $calendar->getEvent($payload['gid']);
        $start = $existing['start'] ?? null;
        $end = $existing['end'] ?? null;
        $attendeeName = $this->attendeeNameFrom($existing, $payload['em']);

        try {
            $calendar->deleteEvent($payload['gid']);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['error' => 'calendar_delete_failed'], 502);
        }

        $type = EventTypeResolver::find($payload['et']);

        $conference = ConferenceResolver::for($type['location'] ?? null);
        if ($conference && !empty($payload['cm'])) {
            $this->quietly(fn () => $conference->delete((string) $payload['cm']));
        }

        $msg = [
            'event_id'       => $payload['gid'],
            'event_type'     => $payload['et'],
            'attendee'       => $payload['em'],
            'start'          => $start,
            'end'            => $end,
            'meet_provider'  => LocationResolver::meetProvider($type ?? []),
            'location_label' => LocationResolver::label($type ?? []),
            'lang'           => $payload['lang'] ?? null,
            'fields'         => array_filter([
                'email' => $payload['em'],
                'name'  => $attendeeName,
            ]),
        ];
        $mailer->send(new BookingCancelled($msg, $type ?? []), $payload['em']);
        if (config('booking.mail.notify_organizer', true) && config('booking.organizer.email')) {
            $mailer->send(new BookingCancelled($msg, $type ?? []), config('booking.organizer.email'));
        }

        try {
            event(new BookingCancelledEvent($msg, $type ?? []));
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * A native conference the active driver can actually create. An event type
     * asking for one the driver does not support is a config mismatch, not a
     * booking failure: warn and let the meeting happen without a link.
     *
     * @param array<string,mixed> $type
     */
    private function nativeConferenceFor(array $type, CalendarProvider $calendar): ?string
    {
        $wanted = LocationResolver::nativeConference($type);
        if ($wanted === null) {
            return null;
        }

        if (in_array($wanted, $calendar->supportedConferences(), true)) {
            return $wanted;
        }

        Log::warning('Booking package: calendar driver cannot create a [' . $wanted . '] conference; creating the event without one.', [
            'driver_supports' => $calendar->supportedConferences(),
        ]);

        return null;
    }

    /**
     * @param array<string,mixed>|null $event Normalised getEvent() result.
     */
    private function attendeeNameFrom(?array $event, string $email): ?string
    {
        foreach ((array) ($event['attendees'] ?? []) as $attendee) {
            if (strcasecmp((string) ($attendee['email'] ?? ''), $email) === 0) {
                $name = $attendee['name'] ?? null;
                return is_string($name) && $name !== '' ? $name : null;
            }
        }

        return null;
    }

    /**
     * Run a best-effort side effect: report failures, never surface them.
     */
    private function quietly(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function validateFormFields(array $defs, array $values): ?string
    {
        foreach ($defs as $field) {
            $name = $field['name'] ?? null;
            if (!$name) {
                continue;
            }
            $val = $values[$name] ?? null;
            $required = (bool) ($field['required'] ?? false);
            if ($required && (is_null($val) || $val === '')) {
                return "Field '{$name}' is required.";
            }
            if (($field['type'] ?? null) === 'email' && $val && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                return "Field '{$name}' must be a valid email.";
            }
        }
        return null;
    }

    private function buildDescription(array $defs, array $values): string
    {
        $lines = [];
        foreach ($defs as $field) {
            $name = $field['name'] ?? null;
            if (!$name || !isset($values[$name]) || $values[$name] === '') {
                continue;
            }
            $label = $field['label'] ?? $name;
            $lines[] = "{$label}: " . (is_scalar($values[$name]) ? (string) $values[$name] : json_encode($values[$name]));
        }
        return implode("\n", $lines);
    }
}
