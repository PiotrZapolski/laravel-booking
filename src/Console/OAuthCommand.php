<?php

namespace Zapol\Booking\Console;

use Illuminate\Console\Command;
use Zapol\Booking\Services\Google\GoogleCalendarService;

class OAuthCommand extends Command
{
    protected $signature = 'booking:google-auth';
    protected $description = 'Run the Google OAuth flow and mint a refresh token for the booking package.';

    public function handle(GoogleCalendarService $calendar): int
    {
        $client = $calendar->client();

        if (!$client->getClientId() || !$client->getClientSecret()) {
            $this->error('BOOKING_GOOGLE_CLIENT_ID and BOOKING_GOOGLE_CLIENT_SECRET must be set in .env first.');
            $this->line('Create OAuth credentials at https://console.cloud.google.com/apis/credentials');
            $this->line('Authorized redirect URI: ' . $client->getRedirectUri());
            return self::FAILURE;
        }

        $authUrl = $client->createAuthUrl();
        $this->info('1) Open this URL in a browser and sign in with the Google account you want to use for this install:');
        $this->line($authUrl);
        $this->line('');
        $code = $this->ask('2) Paste the authorization code Google gave you');

        if (!$code) {
            $this->error('No code provided. Aborting.');
            return self::FAILURE;
        }

        $token = $client->fetchAccessTokenWithAuthCode($code);
        if (isset($token['error'])) {
            $this->error('Failed to exchange code: ' . ($token['error_description'] ?? $token['error']));
            return self::FAILURE;
        }

        $refresh = $token['refresh_token'] ?? null;
        if (!$refresh) {
            $this->error('No refresh_token returned. Make sure the OAuth client is in "offline" access mode and that you have re-consented.');
            return self::FAILURE;
        }

        $this->line('');
        $this->info('Success. Add this to your .env (and `php artisan config:clear` after):');
        $this->line('');
        $this->line('BOOKING_GOOGLE_REFRESH_TOKEN=' . $refresh);
        $this->line('');

        return self::SUCCESS;
    }
}
