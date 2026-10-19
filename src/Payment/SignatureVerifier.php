<?php

declare(strict_types=1);

namespace BookingApi\Payment;

use BookingApi\Http\HttpException;

/**
 * Checks "X-Signature: sha256=<hex>" where hex = HMAC-SHA256(secret, "<timestamp>.<raw body>").
 *
 * The timestamp is part of the signed payload, so an intercepted request cannot be replayed
 * after the tolerance window, and its body cannot be modified.
 */
final class SignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('Webhook secret must not be empty.');
        }
    }

    public function sign(int $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);
    }

    public function verify(?string $timestampHeader, ?string $signatureHeader, string $body, int $now): void
    {
        if ($timestampHeader === null || $signatureHeader === null || !ctype_digit($timestampHeader)) {
            throw HttpException::unauthorized('Missing or malformed signature headers.');
        }

        $timestamp = (int) $timestampHeader;
        if (abs($now - $timestamp) > $this->toleranceSeconds) {
            throw HttpException::unauthorized('Signature timestamp is outside the allowed window.');
        }

        // hash_equals: constant-time comparison, no timing side channel.
        if (!hash_equals($this->sign($timestamp, $body), $signatureHeader)) {
            throw HttpException::unauthorized('Invalid signature.');
        }
    }
}
