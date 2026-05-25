<?php

namespace Zapol\Booking\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Stateless signed payloads. Round-trips a small JSON blob with an HMAC-SHA256
 * signature derived from the host app's APP_KEY. Used for reschedule + cancel
 * links so we don't need a bookings table.
 *
 * Format: base64url(json) + "." + base64url(hmac)
 */
class BookingTokenSigner
{
    public function __construct(private string $appKey)
    {
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is empty; BookingTokenSigner cannot sign tokens.');
        }
    }

    /**
     * @param array<string,mixed> $payload Will get an `exp` timestamp injected if not present.
     */
    public function sign(array $payload, int $ttlSeconds = 5184000): string
    {
        if (!isset($payload['exp'])) {
            $payload['exp'] = time() + $ttlSeconds;
        }

        $body = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = $this->base64UrlEncode(hash_hmac('sha256', $body, $this->derivedKey(), true));

        return $body . '.' . $sig;
    }

    /**
     * @return array<string,mixed>
     * @throws InvalidArgumentException on malformed / tampered / expired tokens
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Malformed booking token.');
        }
        [$body, $sig] = $parts;

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $body, $this->derivedKey(), true));
        if (!hash_equals($expected, $sig)) {
            throw new InvalidArgumentException('Booking token signature mismatch.');
        }

        $payload = json_decode($this->base64UrlDecode($body), true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Booking token payload is not a JSON object.');
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new InvalidArgumentException('Booking token has expired.');
        }

        return $payload;
    }

    private function derivedKey(): string
    {
        // Laravel stores APP_KEY as base64:xxx. Use the raw bytes if so, else
        // the key string itself.
        if (str_starts_with($this->appKey, 'base64:')) {
            $decoded = base64_decode(substr($this->appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $this->appKey;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/')) ?: '';
    }
}
