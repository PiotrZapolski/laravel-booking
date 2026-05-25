<?php

namespace Zapol\Booking\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Throwable;
use Zapol\Booking\Mail\BookingCancelled;
use Zapol\Booking\Mail\BookingConfirmation;
use Zapol\Booking\Mail\BookingConfirmationOrganizer;
use Zapol\Booking\Mail\BookingRescheduled;
use Zapol\Booking\Services\BookingTokenSigner;
use Zapol\Booking\Services\Google\GoogleCalendarService;
use Zapol\Booking\Support\BookingMailer;
use Zapol\Booking\Support\EventTypeResolver;

class BookingController extends Controller
{
    public function store(
        Request $request,
        GoogleCalendarService $calendar,
        BookingTokenSigner $signer,
        BookingMailer $mailer,
    ): JsonResponse {
        $data = $request->validate([
            'event_type' => ['required', 'string'],
            'slot'       => ['required', 'string'],
            'fields'     => ['required', 'array'],
            'timezone'   => ['nullable', 'string'],
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
        $busy = $calendar->freeBusy(
            config('booking.google.busy_calendars', []),
            $start->subMinutes(1),
            $end->addMinutes(1),
        );
        foreach ($busy as $b) {
            if ($start->lessThan($b['end']) && $end->greaterThan($b['start'])) {
                return response()->json(['error' => 'slot_no_longer_available'], 409);
            }
        }

        $attendeeEmail = (string) ($data['fields']['email'] ?? '');
        $attendeeName  = (string) ($data['fields']['name'] ?? '');
        $summary = $type['title'] ?? $data['event_type'];
        $description = $this->buildDescription($type['form_fields'] ?? [], $data['fields']);

        try {
            $created = $calendar->createEvent(
                config('booking.google.calendar_id', 'primary'),
                $summary,
                $description,
                $start,
                $end,
                $organizerTz,
                $attendeeEmail,
                $attendeeName,
                $type['additional_attendees'] ?? [],
                ($type['location'] ?? null) === 'google_meet',
            );
        } catch (Throwable $e) {
            report($e);
            return response()->json(['error' => 'calendar_create_failed', 'message' => $e->getMessage()], 502);
        }

        $token = $signer->sign([
            'gid' => $created['id'],
            'et'  => $data['event_type'],
            'em'  => $attendeeEmail,
        ], 86400 * (int) config('booking.token_ttl_days', 60));

        $rescheduleUrl = URL::route('booking.reschedule', ['token' => $token]);

        $payload = [
            'token'          => $token,
            'event_id'       => $created['id'],
            'event_type'     => $data['event_type'],
            'start'          => $start->toIso8601String(),
            'end'            => $end->toIso8601String(),
            'meet_link'      => $created['meet_link'],
            'html_link'      => $created['html_link'],
            'reschedule_url' => $rescheduleUrl,
            'fields'         => $data['fields'],
        ];

        $mailer->send(new BookingConfirmation($payload, $type), $attendeeEmail);
        if (config('booking.mail.notify_organizer', true) && config('booking.organizer.email')) {
            $mailer->send(new BookingConfirmationOrganizer($payload, $type), config('booking.organizer.email'));
        }

        return response()->json($payload, 201);
    }

    public function show(string $token, BookingTokenSigner $signer, GoogleCalendarService $calendar): JsonResponse
    {
        try {
            $payload = $signer->verify($token);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_token', 'message' => $e->getMessage()], 401);
        }

        $event = $calendar->getEvent(config('booking.google.calendar_id', 'primary'), $payload['gid']);
        if (!$event) {
            return response()->json(['error' => 'event_not_found'], 404);
        }

        return response()->json([
            'event_type' => $payload['et'],
            'attendee'   => $payload['em'],
            'start'      => $event->getStart()->getDateTime() ?? $event->getStart()->getDate(),
            'end'        => $event->getEnd()->getDateTime() ?? $event->getEnd()->getDate(),
            'summary'    => $event->getSummary(),
            'meet_link'  => $event->getHangoutLink(),
        ]);
    }

    public function reschedule(
        Request $request,
        string $token,
        BookingTokenSigner $signer,
        GoogleCalendarService $calendar,
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

        $calendarId = config('booking.google.calendar_id', 'primary');
        try {
            $calendar->patchEvent($calendarId, $payload['gid'], $start, $end, $tz);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['error' => 'calendar_patch_failed', 'message' => $e->getMessage()], 502);
        }

        $rescheduleUrl = URL::route('booking.reschedule', ['token' => $token]);

        $msg = [
            'token'          => $token,
            'event_id'       => $payload['gid'],
            'event_type'     => $payload['et'],
            'start'          => $start->toIso8601String(),
            'end'            => $end->toIso8601String(),
            'reschedule_url' => $rescheduleUrl,
            'attendee'       => $payload['em'],
        ];
        $mailer->send(new BookingRescheduled($msg, $type), $payload['em']);
        if (config('booking.mail.notify_organizer', true) && config('booking.organizer.email')) {
            $mailer->send(new BookingRescheduled($msg, $type), config('booking.organizer.email'));
        }

        return response()->json(['ok' => true] + $msg);
    }

    public function cancel(
        string $token,
        BookingTokenSigner $signer,
        GoogleCalendarService $calendar,
        BookingMailer $mailer,
    ): JsonResponse {
        try {
            $payload = $signer->verify($token);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        try {
            $calendar->deleteEvent(config('booking.google.calendar_id', 'primary'), $payload['gid']);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['error' => 'calendar_delete_failed'], 502);
        }

        $type = EventTypeResolver::find($payload['et']);
        $msg = ['event_type' => $payload['et'], 'attendee' => $payload['em']];
        $mailer->send(new BookingCancelled($msg, $type ?? []), $payload['em']);
        if (config('booking.mail.notify_organizer', true) && config('booking.organizer.email')) {
            $mailer->send(new BookingCancelled($msg, $type ?? []), config('booking.organizer.email'));
        }

        return response()->json(['ok' => true]);
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
