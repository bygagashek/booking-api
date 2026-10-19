<?php

declare(strict_types=1);

namespace BookingApi\Tests\Unit;

use BookingApi\Booking\CreateBooking;
use BookingApi\Http\HttpException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CreateBookingTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2030-01-01T12:00:00+00:00');
    }

    public function testValidInputIsNormalized(): void
    {
        $booking = CreateBooking::fromArray($this->valid([
            'guest_name' => '  Anna  ',
            'guest_phone' => '+375 (29) 123-45-67',
            'starts_at' => '2030-01-02T21:00:00+03:00',
            'ends_at' => '2030-01-02T23:00:00+03:00',
        ]), $this->now);

        self::assertSame('Anna', $booking->guestName);
        self::assertSame('+375291234567', $booking->guestPhone);
        self::assertSame('2030-01-02 18:00:00', $booking->startsAt->format('Y-m-d H:i:s'), 'stored in UTC');
        self::assertSame(0, $booking->depositAmount);
        self::assertSame('BYN', $booking->depositCurrency);
    }

    public function testAllErrorsAreReportedAtOnce(): void
    {
        try {
            CreateBooking::fromArray([
                'table_id' => '1',
                'guest_name' => '',
                'guest_phone' => '123',
                'guests' => 0,
                'starts_at' => 'tomorrow',
                'ends_at' => null,
                'deposit_amount' => 10.5,
                'deposit_currency' => 'byn',
            ], $this->now);
            self::fail('Expected validation error');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(
                ['table_id', 'guest_name', 'guest_phone', 'guests', 'starts_at', 'ends_at', 'deposit_amount', 'deposit_currency'],
                array_keys($e->errors),
            );
        }
    }

    public function testRejectsBookingInThePast(): void
    {
        $this->assertInvalid('starts_at', ['starts_at' => '2030-01-01T11:00:00+00:00', 'ends_at' => '2030-01-01T13:00:00+00:00']);
    }

    public function testRejectsEndBeforeStart(): void
    {
        $this->assertInvalid('ends_at', ['ends_at' => '2030-01-02T17:00:00+00:00']);
    }

    public function testRejectsTooLongBooking(): void
    {
        $this->assertInvalid('ends_at', ['ends_at' => '2030-01-03T00:00:01+00:00']);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function assertInvalid(string $field, array $override): void
    {
        try {
            CreateBooking::fromArray($this->valid($override), $this->now);
            self::fail('Expected validation error');
        } catch (HttpException $e) {
            self::assertSame([$field], array_keys($e->errors));
        }
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function valid(array $override = []): array
    {
        return $override + [
            'table_id' => 1,
            'guest_name' => 'Anna',
            'guest_phone' => '+375291234567',
            'guests' => 2,
            'starts_at' => '2030-01-02T18:00:00+00:00',
            'ends_at' => '2030-01-02T20:00:00+00:00',
        ];
    }
}
