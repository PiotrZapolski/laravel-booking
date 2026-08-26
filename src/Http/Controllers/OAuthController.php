<?php

namespace Zapol\Booking\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use Throwable;
use Zapol\Booking\Services\Google\GoogleCalendarService;

class OAuthController extends Controller
{
    /**
     * Connection landing page - shows current status and a "Connect Google" button.
     * Protected by Laravel signed URLs (artisan command generates a 1-hour link).
     */
    public function connect(Request $request, GoogleCalendarService $calendar): Response
    {
        $client = $calendar->client();

        $missingClient = !$client->getClientId() || !$client->getClientSecret();
        $hasRefreshToken = !empty(config('booking.google.refresh_token'));
        $connectedEmail = null;

        if ($hasRefreshToken && !$missingClient) {
            try {
                $client->refreshToken(config('booking.google.refresh_token'));
                $info = $client->verifyIdToken();
                $connectedEmail = is_array($info) ? ($info['email'] ?? null) : null;
            } catch (Throwable $e) {
                // Best-effort; we'll just show "connected" without email if probe fails.
            }
        }

        $callbackUrl = URL::route('booking.google.callback');
        $startUrl = URL::route('booking.google.start');

        return $this->renderPage('Booking - Google Calendar connection', $this->statusBody(
            missingClient: $missingClient,
            hasRefreshToken: $hasRefreshToken,
            connectedEmail: $connectedEmail,
            startUrl: $startUrl,
            callbackUrl: $callbackUrl,
        ));
    }

    /**
     * Kicks off the OAuth dance. Redirects to Google.
     */
    public function start(GoogleCalendarService $calendar): RedirectResponse|Response
    {
        $client = $calendar->client();
        if (!$client->getClientId() || !$client->getClientSecret()) {
            return $this->renderPage('OAuth client not configured', '<p>Set <code>BOOKING_GOOGLE_CLIENT_ID</code> and <code>BOOKING_GOOGLE_CLIENT_SECRET</code> in <code>.env</code> first, then retry.</p>');
        }
        // Force the consent screen so Google always emits a refresh_token.
        $client->setApprovalPrompt('force');
        $client->setIncludeGrantedScopes(true);
        $client->setRedirectUri(URL::route('booking.google.callback'));

        return redirect()->away($client->createAuthUrl());
    }

    /**
     * OAuth callback. Exchanges the code for a refresh token and displays it
     * for the operator to paste into .env. No state is persisted by the package.
     */
    public function callback(Request $request, GoogleCalendarService $calendar): Response
    {
        $error = (string) $request->query('error', '');
        $code = (string) $request->query('code', '');

        if ($error !== '') {
            return $this->renderPage('Authorisation failed', '<p>Google returned: <code>' . e($error) . '</code></p>' . $this->backLink());
        }

        if ($code === '') {
            return $this->renderPage('Awaiting Google redirect', '<p>This page receives the OAuth code from Google. Start the flow from <a href="' . e(URL::route('booking.google.start')) . '">/booking/google/start</a>.</p>');
        }

        $client = $calendar->client();
        if (!$client->getClientId() || !$client->getClientSecret()) {
            return $this->renderPage('OAuth client not configured', '<p>Set <code>BOOKING_GOOGLE_CLIENT_ID</code> and <code>BOOKING_GOOGLE_CLIENT_SECRET</code> in <code>.env</code> first, then retry.</p>');
        }

        $client->setRedirectUri(URL::route('booking.google.callback'));

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);
        } catch (Throwable $e) {
            return $this->renderPage('Token exchange failed', '<p><code>' . e($e->getMessage()) . '</code></p>' . $this->backLink());
        }

        if (isset($token['error'])) {
            return $this->renderPage('Token exchange failed', '<p><code>' . e($token['error_description'] ?? $token['error']) . '</code></p>' . $this->backLink());
        }

        $refresh = $token['refresh_token'] ?? null;
        if (!$refresh) {
            return $this->renderPage('No refresh_token returned', ''
                . '<p>Google did not return a refresh token. This usually happens because you have previously granted access from this OAuth client.</p>'
                . '<p>Open <a href="https://myaccount.google.com/permissions" target="_blank" rel="noopener">myaccount.google.com/permissions</a>, remove the existing grant for this app, then '
                . '<a href="' . e(URL::route('booking.google.start')) . '">retry</a>.</p>'
            );
        }

        $body = '<p>Successfully authorised. Paste this line into your <code>.env</code> file:</p>'
            . '<pre id="env-line" style="background:#0f172a;color:#bbf7d0;padding:14px;border-radius:8px;overflow:auto;user-select:all;">BOOKING_GOOGLE_REFRESH_TOKEN=' . e($refresh) . '</pre>'
            . '<button onclick="navigator.clipboard.writeText(document.getElementById(\'env-line\').innerText);this.textContent=\'Copied ✓\'" '
            . 'style="margin-top:8px;padding:8px 14px;background:#0f172a;color:#fff;border:0;border-radius:6px;cursor:pointer;font-family:inherit;">Copy to clipboard</button>'
            . '<p style="margin-top:24px;">Then run <code>php artisan config:clear</code> and reload your site.</p>'
            . '<p style="color:#6b7280;font-size:13px;">This refresh token never expires until you revoke access. Treat it like a password.</p>';

        return $this->renderPage('Authorisation complete ✓', $body);
    }

    private function statusBody(bool $missingClient, bool $hasRefreshToken, ?string $connectedEmail, string $startUrl, string $callbackUrl): string
    {
        $status = $hasRefreshToken
            ? '<div style="background:#dcfce7;color:#166534;padding:12px 14px;border-radius:8px;font-weight:500;">✓ Connected' . ($connectedEmail ? ' as <code>' . e($connectedEmail) . '</code>' : '') . '</div>'
            : '<div style="background:#fef3c7;color:#92400e;padding:12px 14px;border-radius:8px;font-weight:500;">Not connected</div>';

        $clientWarning = $missingClient
            ? '<div style="background:#fef2f2;color:#991b1b;padding:12px 14px;border-radius:8px;margin-top:12px;">'
                . '<strong>OAuth client not configured.</strong> Set <code>BOOKING_GOOGLE_CLIENT_ID</code> and <code>BOOKING_GOOGLE_CLIENT_SECRET</code> in <code>.env</code> first.'
                . '</div>'
            : '';

        $cta = !$missingClient
            ? '<a href="' . e($startUrl) . '" style="display:inline-block;margin-top:24px;padding:12px 22px;background:#0f172a;color:#fff;text-decoration:none;border-radius:8px;font-weight:500;">'
                . ($hasRefreshToken ? 'Reconnect Google Account' : 'Connect Google Account')
                . '</a>'
            : '';

        $setup = '<details style="margin-top:36px;"><summary style="cursor:pointer;color:#6b7280;">Google Cloud Console setup</summary>'
            . '<ol style="line-height:1.7;color:#1f303a;">'
            . '<li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → Credentials</a>.</li>'
            . '<li>Create an OAuth 2.0 Client ID. Application type: <strong>Web application</strong>.</li>'
            . '<li>Add this Authorised redirect URI:<pre style="background:#f4f4f5;padding:10px;border-radius:6px;user-select:all;">' . e($callbackUrl) . '</pre></li>'
            . '<li>Enable the <a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener">Google Calendar API</a> on the same project.</li>'
            . '<li>Copy the client ID + client secret into <code>BOOKING_GOOGLE_CLIENT_ID</code> and <code>BOOKING_GOOGLE_CLIENT_SECRET</code> in <code>.env</code>.</li>'
            . '<li>Click "Connect Google Account" above.</li>'
            . '</ol></details>';

        return $status . $clientWarning . $cta . $setup;
    }

    private function backLink(): string
    {
        return '<p style="margin-top:18px;"><a href="' . e(URL::route('booking.google.start')) . '">Try again →</a></p>';
    }

    private function renderPage(string $title, string $body): Response
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title>'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>'
            . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:680px;margin:48px auto;padding:0 24px;color:#1f303a;line-height:1.55;}'
            . 'h1{font-size:22px;margin:0 0 22px;font-weight:700;}'
            . 'code{background:#f4f4f5;padding:2px 6px;border-radius:4px;font-size:13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;}'
            . 'pre{font-size:13px;line-height:1.4;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;margin:8px 0;}'
            . 'a{color:#2563eb;}'
            . 'summary{outline:none;}'
            . '</style></head><body><h1>' . e($title) . '</h1>' . $body . '</body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
