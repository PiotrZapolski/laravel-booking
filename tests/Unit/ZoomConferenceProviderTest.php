<?php

namespace Zapol\Booking\Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zapol\Booking\Conference\ZoomConferenceProvider;

class ZoomConferenceProviderTest extends TestCase
{
    /** @return array<string,mixed> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'account_id'    => 'acc-123',
            'client_id'     => 'cid-456',
            'client_secret' => 'secret-789',
            'user'          => 'host@example.com',
        ], $overrides);
    }

    private function tokenStub(): array
    {
        return ['access_token' => 'zoom-token', 'token_type' => 'bearer', 'expires_in' => 3600];
    }

    /** @return array{summary:string,start:CarbonImmutable,end:CarbonImmutable,timezone:string,attendee:array{email:string,name:string}} */
    private function draft(): array
    {
        return [
            'summary'  => 'Consultation with Jan',
            // 12:00 Warsaw (UTC+2 in June) = 10:00 UTC, 45 minutes long.
            'start'    => CarbonImmutable::parse('2030-06-03 12:00:00', 'Europe/Warsaw'),
            'end'      => CarbonImmutable::parse('2030-06-03 12:45:00', 'Europe/Warsaw'),
            'timezone' => 'Europe/Warsaw',
            'attendee' => ['email' => 'jan@example.com', 'name' => 'Jan Kowalski'],
        ];
    }

    public function test_token_request_uses_basic_auth_and_account_credentials_grant(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response(['id' => 1, 'join_url' => 'https://zoom.us/j/1']),
        ]);

        (new ZoomConferenceProvider($this->config(), $http))->create($this->draft());

        $http->assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'zoom.us/oauth/token')) {
                return false;
            }

            return $request->method() === 'POST'
                && str_contains($request->url(), 'grant_type=account_credentials')
                && str_contains($request->url(), 'account_id=acc-123')
                && $request->header('Authorization')[0] === 'Basic ' . base64_encode('cid-456:secret-789');
        });
    }

    public function test_access_token_is_reused_across_calls(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response(['id' => 1, 'join_url' => 'https://zoom.us/j/1']),
        ]);

        $zoom = new ZoomConferenceProvider($this->config(), $http);
        $zoom->create($this->draft());
        $zoom->delete('1');

        $tokenCalls = 0;
        $http->assertSent(function (Request $request) use (&$tokenCalls) {
            if (str_contains($request->url(), 'zoom.us/oauth/token')) {
                $tokenCalls++;
            }

            return true;
        });

        $this->assertSame(1, $tokenCalls);
    }

    public function test_create_posts_a_scheduled_meeting_and_returns_join_details(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response([
                'id'       => 87654321098,
                'join_url' => 'https://zoom.us/j/87654321098?pwd=abc',
                'password' => 'p4ss',
            ]),
        ]);

        $meeting = (new ZoomConferenceProvider($this->config(), $http))->create($this->draft());

        $this->assertSame('87654321098', $meeting['id'], 'The meeting id must be returned as a string.');
        $this->assertSame('https://zoom.us/j/87654321098?pwd=abc', $meeting['join_url']);
        $this->assertSame('p4ss', $meeting['password']);

        $http->assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/v2/users/')) {
                return false;
            }

            $body = $request->data();

            // The host segment may or may not keep the "@" percent-encoded.
            $expectedUrls = [
                'https://api.zoom.us/v2/users/host%40example.com/meetings',
                'https://api.zoom.us/v2/users/host@example.com/meetings',
            ];

            return $request->method() === 'POST'
                && in_array($request->url(), $expectedUrls, true)
                && $request->header('Authorization')[0] === 'Bearer zoom-token'
                && $body['topic'] === 'Consultation with Jan'
                && $body['type'] === 2
                && $body['start_time'] === '2030-06-03T10:00:00Z'
                && $body['duration'] === 45
                && $body['timezone'] === 'Europe/Warsaw'
                && $body['settings']['join_before_host'] === false
                && $body['settings']['waiting_room'] === true;
        });
    }

    public function test_create_returns_null_password_when_zoom_sends_none(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response(['id' => 5, 'join_url' => 'https://zoom.us/j/5']),
        ]);

        $meeting = (new ZoomConferenceProvider($this->config(), $http))->create($this->draft());

        $this->assertSame('5', $meeting['id']);
        $this->assertNull($meeting['password']);
    }

    public function test_create_defaults_to_the_app_owner_when_no_user_is_configured(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response(['id' => 9, 'join_url' => 'https://zoom.us/j/9']),
        ]);

        $config = $this->config();
        unset($config['user']);

        (new ZoomConferenceProvider($config, $http))->create($this->draft());

        $http->assertSent(fn (Request $request) => $request->url() === 'https://api.zoom.us/v2/users/me/meetings');
    }

    public function test_reschedule_patches_the_meeting(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response('', 204),
        ]);

        (new ZoomConferenceProvider($this->config(), $http))->reschedule(
            '87654321098',
            CarbonImmutable::parse('2030-06-04 09:30:00', 'Europe/Warsaw'),
            CarbonImmutable::parse('2030-06-04 10:00:00', 'Europe/Warsaw'),
            'Europe/Warsaw'
        );

        $http->assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/v2/meetings/')) {
                return false;
            }

            $body = $request->data();

            return $request->method() === 'PATCH'
                && $request->url() === 'https://api.zoom.us/v2/meetings/87654321098'
                && $body['start_time'] === '2030-06-04T07:30:00Z'
                && $body['duration'] === 30
                && $body['timezone'] === 'Europe/Warsaw';
        });
    }

    public function test_delete_removes_the_meeting_without_a_reminder(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response('', 204),
        ]);

        (new ZoomConferenceProvider($this->config(), $http))->delete('87654321098');

        $http->assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.zoom.us/v2/meetings/87654321098?schedule_for_reminder=false');
    }

    public function test_delete_tolerates_an_already_removed_meeting(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response(['code' => 3001, 'message' => 'Meeting does not exist'], 404),
        ]);

        (new ZoomConferenceProvider($this->config(), $http))->delete('404404404');

        $this->assertTrue(true, 'A 404 on delete must not throw.');
    }

    public function test_incomplete_config_throws_before_any_request(): void
    {
        $http = new Factory();
        $http->fake();

        $zoom = new ZoomConferenceProvider($this->config(['client_secret' => null]), $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BOOKING_ZOOM_CLIENT_SECRET');

        $zoom->create($this->draft());
    }

    public function test_token_error_response_throws(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response(['reason' => 'Invalid client_id or client_secret'], 401),
        ]);

        $zoom = new ZoomConferenceProvider($this->config(), $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid client_id or client_secret');

        $zoom->create($this->draft());
    }

    public function test_zoom_error_on_create_throws_with_the_zoom_message(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response(['code' => 1001, 'message' => 'User does not exist: host@example.com'], 404),
        ]);

        $zoom = new ZoomConferenceProvider($this->config(), $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('User does not exist: host@example.com');

        $zoom->create($this->draft());
    }

    public function test_reschedule_error_response_throws(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response(['code' => 3001, 'message' => 'Meeting does not exist'], 400),
        ]);

        $zoom = new ZoomConferenceProvider($this->config(), $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Meeting does not exist');

        $zoom->reschedule(
            '1',
            CarbonImmutable::parse('2030-06-04 09:30:00', 'UTC'),
            CarbonImmutable::parse('2030-06-04 10:00:00', 'UTC'),
            'UTC'
        );
    }

    public function test_missing_join_url_in_the_response_throws(): void
    {
        $http = new Factory();
        $http->fake([
            'zoom.us/oauth/token*' => Factory::response($this->tokenStub()),
            'api.zoom.us/*'        => Factory::response(['id' => 42]),
        ]);

        $zoom = new ZoomConferenceProvider($this->config(), $http);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('join_url');

        $zoom->create($this->draft());
    }
}
