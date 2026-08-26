<?php

namespace Zapol\Booking\Conference;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Zapol\Booking\Contracts\ConferenceProvider;

/**
 * Creates Zoom meetings through a Server-to-Server OAuth app.
 *
 * The app owner (or the account user given in `booking.zoom.user`) hosts every
 * meeting. Scopes required on the marketplace app: meeting:write:admin and
 * meeting:read:admin (granular equivalents: meeting:write:meeting:admin,
 * meeting:update:meeting:admin, meeting:delete:meeting:admin).
 */
class ZoomConferenceProvider implements ConferenceProvider
{
    /** Server-to-Server OAuth token endpoint. */
    private const OAUTH_URL = 'https://zoom.us/oauth/token';

    /** REST API base, without a trailing slash. */
    private const API_BASE = 'https://api.zoom.us';

    /** Access token cached for the lifetime of this instance (one request). */
    private ?string $accessToken = null;

    /**
     * @param array $config The `booking.zoom` config array: account_id, client_id, client_secret, user.
     */
    public function __construct(private array $config, private Factory $http)
    {
    }

    /**
     * @param array{summary:string,start:CarbonImmutable,end:CarbonImmutable,timezone:string,attendee:array{email:string,name:string}} $draft
     * @return array{id:string,join_url:string,password:?string}
     */
    public function create(array $draft): array
    {
        $start = $draft['start'];
        $end = $draft['end'];
        $timezone = $draft['timezone'] ?? 'UTC';
        $user = (string) ($this->config['user'] ?? 'me');

        if ($user === '') {
            $user = 'me';
        }

        $response = $this->client()->post(
            self::API_BASE . '/v2/users/' . rawurlencode($user) . '/meetings',
            [
                'topic'      => (string) ($draft['summary'] ?? 'Meeting'),
                'type'       => 2,
                'start_time' => $start->utc()->format('Y-m-d\TH:i:s\Z'),
                'duration'   => $this->durationMinutes($start, $end),
                'timezone'   => $timezone,
                'settings'   => [
                    'join_before_host' => false,
                    'waiting_room'     => true,
                ],
            ]
        );

        $data = $this->decode($response, 'create the Zoom meeting');

        $id = (string) ($data['id'] ?? '');
        $joinUrl = (string) ($data['join_url'] ?? '');

        if ($id === '' || $joinUrl === '') {
            throw new RuntimeException('Zoom did not return a meeting id and join_url.');
        }

        $password = isset($data['password']) && $data['password'] !== ''
            ? (string) $data['password']
            : null;

        return [
            'id'       => $id,
            'join_url' => $joinUrl,
            'password' => $password,
        ];
    }

    public function reschedule(string $meetingId, CarbonImmutable $start, CarbonImmutable $end, string $timezone): void
    {
        $response = $this->client()->patch(
            self::API_BASE . '/v2/meetings/' . rawurlencode($meetingId),
            [
                'start_time' => $start->utc()->format('Y-m-d\TH:i:s\Z'),
                'duration'   => $this->durationMinutes($start, $end),
                'timezone'   => $timezone,
            ]
        );

        $this->assertOk($response, 'reschedule the Zoom meeting');
    }

    public function delete(string $meetingId): void
    {
        $response = $this->client()->delete(
            self::API_BASE . '/v2/meetings/' . rawurlencode($meetingId) . '?schedule_for_reminder=false'
        );

        // A meeting somebody already removed in the Zoom UI is not an error for us.
        if ($response->status() === 404) {
            return;
        }

        $this->assertOk($response, 'delete the Zoom meeting');
    }

    /** Authenticated JSON client for the Zoom REST API. */
    private function client(): PendingRequest
    {
        return $this->http
            ->timeout(15)
            ->withToken($this->accessToken())
            ->acceptJson();
    }

    /** Fetches (once per instance) a Server-to-Server OAuth access token. */
    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $accountId = (string) ($this->config['account_id'] ?? '');
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($accountId === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException(
                'Zoom is not configured: set BOOKING_ZOOM_ACCOUNT_ID, BOOKING_ZOOM_CLIENT_ID and BOOKING_ZOOM_CLIENT_SECRET.'
            );
        }

        $query = http_build_query([
            'grant_type' => 'account_credentials',
            'account_id' => $accountId,
        ]);

        $response = $this->http
            ->timeout(15)
            ->withBasicAuth($clientId, $clientSecret)
            ->acceptJson()
            ->post(self::OAUTH_URL . '?' . $query);

        $data = $this->decode($response, 'obtain a Zoom access token');

        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('Zoom did not return an access_token.');
        }

        return $this->accessToken = $token;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(Response $response, string $action): array
    {
        $this->assertOk($response, $action);

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    private function assertOk(Response $response, string $action): void
    {
        if (! $response->failed()) {
            return;
        }

        throw new RuntimeException(
            'Zoom API failed to ' . $action . ' (HTTP ' . $response->status() . '): ' . $this->errorMessage($response)
        );
    }

    private function errorMessage(Response $response): string
    {
        $data = $response->json();

        if (is_array($data)) {
            foreach (['message', 'error_description', 'reason', 'error'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                    return $data[$key];
                }
            }
        }

        $body = trim((string) $response->body());

        return $body !== '' ? mb_substr($body, 0, 500) : 'no error message returned';
    }

    /** Meeting length in whole minutes, never below 1. */
    private function durationMinutes(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $minutes = (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);

        return max(1, $minutes);
    }
}
