<?php

namespace Zapol\Booking\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zapol\Booking\Calendar\MicrosoftCalendarProvider;

/**
 * Container-free tests: the provider only ever touches the injected HTTP
 * factory, so a bare `new Factory()` with fakes is enough to drive it.
 */
class MicrosoftCalendarProviderTest extends TestCase
{
    /** @return array<string,mixed> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'tenant_id'      => 'tenant-uuid',
            'client_id'      => 'client-uuid',
            'client_secret'  => 's3cret',
            'user'           => 'piotr@contoso.com',
            'calendar_id'    => null,
            'busy_schedules' => ['room-a@contoso.com'],
        ], $overrides);
    }

    private function tokenStub(): array
    {
        return ['login.microsoftonline.com/*' => Factory::response([
            'token_type'   => 'Bearer',
            'expires_in'   => 3599,
            'access_token' => 'graph-token',
        ])];
    }

    public function test_free_busy_maps_busy_oof_tentative_and_skips_free(): void
    {
        $http = new Factory();
        $http->fake($this->tokenStub() + [
            '*/calendar/getSchedule' => Factory::response([
                'value' => [
                    [
                        'scheduleId'    => 'piotr@contoso.com',
                        'scheduleItems' => [
                            [
                                'status' => 'busy',
                                'start'  => ['dateTime' => '2026-03-02T09:00:00.0000000', 'timeZone' => 'UTC'],
                                'end'    => ['dateTime' => '2026-03-02T09:30:00.0000000', 'timeZone' => 'UTC'],
                            ],
                            [
                                'status' => 'free',
                                'start'  => ['dateTime' => '2026-03-02T10:00:00.0000000', 'timeZone' => 'UTC'],
                                'end'    => ['dateTime' => '2026-03-02T10:30:00.0000000', 'timeZone' => 'UTC'],
                            ],
                            [
                                'status' => 'oof',
                                'start'  => ['dateTime' => '2026-03-02T11:00:00.0000000', 'timeZone' => 'UTC'],
                                'end'    => ['dateTime' => '2026-03-02T12:00:00.0000000', 'timeZone' => 'UTC'],
                            ],
                        ],
                    ],
                    [
                        'scheduleId'    => 'room-a@contoso.com',
                        'scheduleItems' => [
                            [
                                'status' => 'tentative',
                                // A non-UTC zone must be honoured: the string carries no offset.
                                'start'  => ['dateTime' => '2026-03-02T14:00:00.0000000', 'timeZone' => 'Europe/Warsaw'],
                                'end'    => ['dateTime' => '2026-03-02T14:15:00.0000000', 'timeZone' => 'Europe/Warsaw'],
                            ],
                            [
                                'status' => 'workingElsewhere',
                                'start'  => ['dateTime' => '2026-03-02T16:00:00.0000000', 'timeZone' => 'UTC'],
                                'end'    => ['dateTime' => '2026-03-02T16:45:00.0000000', 'timeZone' => 'UTC'],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $provider = new MicrosoftCalendarProvider($this->config(), $http);

        $busy = $provider->freeBusy(
            CarbonImmutable::parse('2026-03-02T00:00:00Z'),
            CarbonImmutable::parse('2026-03-03T00:00:00Z')
        );

        $this->assertCount(4, $busy);

        $this->assertSame('2026-03-02T09:00:00+00:00', $busy[0]['start']->toIso8601String());
        $this->assertSame('2026-03-02T09:30:00+00:00', $busy[0]['end']->toIso8601String());

        // The `free` item was dropped, so oof is next.
        $this->assertSame('2026-03-02T11:00:00+00:00', $busy[1]['start']->toIso8601String());

        // Europe/Warsaw in March is still +01:00.
        $this->assertSame('2026-03-02T13:00:00+00:00', $busy[2]['start']->utc()->toIso8601String());
        $this->assertSame('2026-03-02T16:00:00+00:00', $busy[3]['start']->toIso8601String());

        $http->assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/calendar/getSchedule')) {
                return false;
            }

            $body = $request->data();

            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer graph-token')
                && $body['schedules'] === ['piotr@contoso.com', 'room-a@contoso.com']
                && $body['availabilityViewInterval'] === 15
                && $body['startTime'] === ['dateTime' => '2026-03-02T00:00:00', 'timeZone' => 'UTC']
                && $body['endTime'] === ['dateTime' => '2026-03-03T00:00:00', 'timeZone' => 'UTC'];
        });

        $http->assertSent(fn (Request $request) => str_contains($request->url(), 'login.microsoftonline.com/tenant-uuid/oauth2/v2.0/token')
            && $request->data()['grant_type'] === 'client_credentials'
            && $request->data()['scope'] === 'https://graph.microsoft.com/.default');
    }

    public function test_create_event_with_teams_conference(): void
    {
        $http = new Factory();
        $http->fake($this->tokenStub() + [
            '*/events' => Factory::response([
                'id'            => 'AAMkAGI2evt',
                'webLink'       => 'https://outlook.office365.com/calendar/item/AAMkAGI2evt',
                'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/xyz'],
            ], 201),
        ]);

        $provider = new MicrosoftCalendarProvider($this->config(), $http);

        $created = $provider->createEvent([
            'summary'              => 'Konsultacja',
            'description'          => 'Booked via the widget',
            'start'                => CarbonImmutable::parse('2026-03-02T09:00:00Z'),
            'end'                  => CarbonImmutable::parse('2026-03-02T09:30:00Z'),
            'timezone'             => 'Europe/Warsaw',
            'attendee'             => ['email' => 'jan@example.com', 'name' => 'Jan Kowalski'],
            'additional_attendees' => ['asystent@contoso.com'],
            'conference'           => 'teams',
            'location'             => null,
        ]);

        $this->assertSame('AAMkAGI2evt', $created['id']);
        $this->assertSame('https://outlook.office365.com/calendar/item/AAMkAGI2evt', $created['html_link']);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/xyz', $created['meet_link']);

        $http->assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/events')) {
                return false;
            }

            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/users/piotr%40contoso.com/calendar/events')
                && $body['subject'] === 'Konsultacja'
                && $body['body'] === ['contentType' => 'text', 'content' => 'Booked via the widget']
                // 09:00 UTC is 10:00 in Warsaw, and the zone travels with it.
                && $body['start'] === ['dateTime' => '2026-03-02T10:00:00', 'timeZone' => 'Europe/Warsaw']
                && $body['end'] === ['dateTime' => '2026-03-02T10:30:00', 'timeZone' => 'Europe/Warsaw']
                && $body['isOnlineMeeting'] === true
                && $body['onlineMeetingProvider'] === 'teamsForBusiness'
                && ! array_key_exists('location', $body)
                && $body['attendees'] === [
                    ['emailAddress' => ['address' => 'jan@example.com', 'name' => 'Jan Kowalski'], 'type' => 'required'],
                    ['emailAddress' => ['address' => 'asystent@contoso.com', 'name' => 'asystent@contoso.com'], 'type' => 'required'],
                ];
        });
    }

    public function test_create_event_without_conference_does_not_ask_for_an_online_meeting(): void
    {
        $http = new Factory();
        $http->fake($this->tokenStub() + [
            '*/events' => Factory::response(['id' => 'evt-2', 'webLink' => 'https://outlook.office365.com/x'], 201),
        ]);

        $provider = new MicrosoftCalendarProvider(
            $this->config(['calendar_id' => 'AAMkCalendarId==']),
            $http
        );

        $created = $provider->createEvent([
            'summary'              => 'Rozmowa',
            'description'          => '',
            'start'                => CarbonImmutable::parse('2026-03-02T09:00:00Z'),
            'end'                  => CarbonImmutable::parse('2026-03-02T09:30:00Z'),
            'timezone'             => 'UTC',
            'attendee'             => ['email' => 'jan@example.com', 'name' => 'Jan Kowalski'],
            'additional_attendees' => [],
            'conference'           => null,
            'location'             => 'https://zoom.us/j/123456789',
        ]);

        $this->assertSame('evt-2', $created['id']);
        $this->assertNull($created['meet_link']);

        $http->assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/events')) {
                return false;
            }

            $body = $request->data();

            return ! array_key_exists('isOnlineMeeting', $body)
                && ! array_key_exists('onlineMeetingProvider', $body)
                && $body['location'] === ['displayName' => 'https://zoom.us/j/123456789']
                // A pinned calendar id replaces the default `calendar` segment.
                && str_contains($request->url(), '/calendars/AAMkCalendarId%3D%3D/events');
        });
    }

    public function test_get_event_normalises_the_graph_payload(): void
    {
        $http = new Factory();
        $http->fake($this->tokenStub() + [
            '*/events/*' => Factory::response([
                'id'            => 'evt-3',
                'subject'       => 'Konsultacja',
                'webLink'       => 'https://outlook.office365.com/calendar/item/evt-3',
                'start'         => ['dateTime' => '2026-03-02T10:00:00.0000000', 'timeZone' => 'Europe/Warsaw'],
                'end'           => ['dateTime' => '2026-03-02T10:30:00.0000000', 'timeZone' => 'Europe/Warsaw'],
                'onlineMeeting' => ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/xyz'],
                'attendees'     => [
                    ['type' => 'required', 'emailAddress' => ['address' => 'jan@example.com', 'name' => 'Jan Kowalski']],
                    ['type' => 'required', 'emailAddress' => ['address' => 'asystent@contoso.com', 'name' => '']],
                    ['type' => 'required', 'emailAddress' => ['name' => 'Broken entry']],
                ],
            ]),
        ]);

        $provider = new MicrosoftCalendarProvider($this->config(), $http);

        $event = $provider->getEvent('evt-3');

        $this->assertSame('evt-3', $event['id']);
        $this->assertSame('Konsultacja', $event['summary']);
        $this->assertSame('2026-03-02T10:00:00+01:00', $event['start']);
        $this->assertSame('2026-03-02T10:30:00+01:00', $event['end']);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/xyz', $event['meet_link']);
        $this->assertSame([
            ['email' => 'jan@example.com', 'name' => 'Jan Kowalski'],
            ['email' => 'asystent@contoso.com', 'name' => null],
        ], $event['attendees']);

        $http->assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/users/piotr%40contoso.com/events/evt-3')
            && str_contains(urldecode($request->url()), '$select=id,subject,start,end,attendees,onlineMeeting,webLink'));
    }

    public function test_missing_event_returns_null(): void
    {
        $http = new Factory();
        $http->fake($this->tokenStub() + [
            '*/events/*' => Factory::response(['error' => ['code' => 'ErrorItemNotFound', 'message' => 'The specified object was not found in the store.']], 404),
        ]);

        $provider = new MicrosoftCalendarProvider($this->config(), $http);

        $this->assertNull($provider->getEvent('gone'));
    }

    public function test_graph_error_is_reported_as_a_runtime_exception(): void
    {
        $http = new Factory();
        $http->fake($this->tokenStub() + [
            '*/events' => Factory::response([
                'error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.'],
            ], 403),
        ]);

        $provider = new MicrosoftCalendarProvider($this->config(), $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access is denied. Check credentials and try again.');

        $provider->createEvent([
            'summary'              => 'Konsultacja',
            'description'          => '',
            'start'                => CarbonImmutable::parse('2026-03-02T09:00:00Z'),
            'end'                  => CarbonImmutable::parse('2026-03-02T09:30:00Z'),
            'timezone'             => 'UTC',
            'attendee'             => ['email' => 'jan@example.com', 'name' => 'Jan Kowalski'],
            'additional_attendees' => [],
            'conference'           => 'teams',
            'location'             => null,
        ]);
    }

    public function test_incomplete_config_throws_before_any_request(): void
    {
        $http = new Factory();
        $http->fake();

        $provider = new MicrosoftCalendarProvider($this->config(['user' => null]), $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOOKING_MS_USER');

        $provider->freeBusy(
            CarbonImmutable::parse('2026-03-02T00:00:00Z'),
            CarbonImmutable::parse('2026-03-03T00:00:00Z')
        );
    }

    public function test_missing_client_secret_throws_when_a_token_is_needed(): void
    {
        $http = new Factory();
        $http->fake();

        $provider = new MicrosoftCalendarProvider($this->config(['client_secret' => '']), $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOOKING_MS_CLIENT_SECRET');

        $provider->deleteEvent('evt-1');
    }

    public function test_supported_conferences(): void
    {
        $provider = new MicrosoftCalendarProvider($this->config(), new Factory());

        $this->assertSame(['teams'], $provider->supportedConferences());
    }

    public function test_update_event_time_patches_start_and_end(): void
    {
        $http = new Factory();
        $http->fake($this->tokenStub() + ['*/events/*' => Factory::response(['id' => 'evt-1'])]);

        $provider = new MicrosoftCalendarProvider($this->config(), $http);

        $provider->updateEventTime(
            'evt-1',
            CarbonImmutable::parse('2026-03-05T08:00:00Z'),
            CarbonImmutable::parse('2026-03-05T08:30:00Z'),
            'Europe/Warsaw'
        );

        $http->assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/events/evt-1')) {
                return false;
            }

            return $request->method() === 'PATCH'
                && $request->data() === [
                    'start' => ['dateTime' => '2026-03-05T09:00:00', 'timeZone' => 'Europe/Warsaw'],
                    'end'   => ['dateTime' => '2026-03-05T09:30:00', 'timeZone' => 'Europe/Warsaw'],
                ];
        });
    }
}
