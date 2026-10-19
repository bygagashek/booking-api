<?php

declare(strict_types=1);

namespace BookingApi\Tests\Unit;

use BookingApi\Http\HttpException;
use BookingApi\Payment\SignatureVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    private const NOW = 1_900_000_000;
    private const BODY = '{"event_id":"evt_1"}';

    public function testAcceptsValidSignature(): void
    {
        $verifier = new SignatureVerifier('secret');

        $verifier->verify((string) self::NOW, $verifier->sign(self::NOW, self::BODY), self::BODY, self::NOW + 10);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function invalidRequests(): iterable
    {
        $verifier = new SignatureVerifier('secret');
        $signature = $verifier->sign(self::NOW, self::BODY);

        yield 'missing timestamp' => [null, $signature, self::BODY];
        yield 'missing signature' => [(string) self::NOW, null, self::BODY];
        yield 'non-numeric timestamp' => ['abc', $signature, self::BODY];
        yield 'tampered body' => [(string) self::NOW, $signature, '{"event_id":"evt_2"}'];
        yield 'tampered timestamp' => [(string) (self::NOW + 1), $signature, self::BODY];
        yield 'other secret' => [(string) self::NOW, (new SignatureVerifier('other'))->sign(self::NOW, self::BODY), self::BODY];
        yield 'too old' => [(string) (self::NOW - 301), $verifier->sign(self::NOW - 301, self::BODY), self::BODY];
    }

    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidRequest(?string $timestamp, ?string $signature, string $body): void
    {
        try {
            (new SignatureVerifier('secret'))->verify($timestamp, $signature, $body, self::NOW);
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status);
        }
    }
}
