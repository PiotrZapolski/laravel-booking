<?php

namespace Zapol\Booking\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zapol\Booking\Services\BookingTokenSigner;

class BookingTokenSignerTest extends TestCase
{
    public function test_round_trip(): void
    {
        $signer = new BookingTokenSigner('base64:' . base64_encode(random_bytes(32)));
        $token = $signer->sign(['gid' => 'abc', 'et' => 'consultation', 'em' => 'jan@x.pl']);
        $payload = $signer->verify($token);
        $this->assertSame('abc', $payload['gid']);
        $this->assertSame('consultation', $payload['et']);
        $this->assertSame('jan@x.pl', $payload['em']);
        $this->assertGreaterThan(time(), $payload['exp']);
    }

    public function test_tamper_detection(): void
    {
        $signer = new BookingTokenSigner('s3cret');
        $token = $signer->sign(['gid' => 'abc']);
        [$body, $sig] = explode('.', $token);
        $body = rtrim(strtr(base64_encode(json_encode(['gid' => 'evil', 'exp' => time() + 3600])), '+/', '-_'), '=');
        $this->expectException(InvalidArgumentException::class);
        $signer->verify($body . '.' . $sig);
    }

    public function test_expired_token_rejected(): void
    {
        $signer = new BookingTokenSigner('s3cret');
        $token = $signer->sign(['gid' => 'abc', 'exp' => time() - 60]);
        $this->expectException(InvalidArgumentException::class);
        $signer->verify($token);
    }

    public function test_malformed_token_rejected(): void
    {
        $signer = new BookingTokenSigner('s3cret');
        $this->expectException(InvalidArgumentException::class);
        $signer->verify('not-a-token');
    }
}
