<?php

declare(strict_types=1);

namespace BookingApi\Booking;

use BookingApi\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Validated input of POST /bookings.
 */
final class CreateBooking
{
    private const MAX_DURATION_HOURS = 6;

    private function __construct(
        public readonly int $tableId,
        public readonly string $guestName,
        public readonly string $guestPhone,
        public readonly int $guests,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly int $depositAmount,
        public readonly string $depositCurrency,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, DateTimeImmutable $now): self
    {
        $errors = [];

        $tableId = $data['table_id'] ?? null;
        if (!is_int($tableId) || $tableId < 1) {
            $errors['table_id'] = 'Must be a positive integer.';
        }

        $name = is_string($data['guest_name'] ?? null) ? trim($data['guest_name']) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['guest_name'] = 'Required, up to 100 characters.';
        }

        $phone = is_string($data['guest_phone'] ?? null) ? preg_replace('/[^\d+]/', '', $data['guest_phone']) : '';
        if (preg_match('/^\+?\d{10,15}$/', (string) $phone) !== 1) {
            $errors['guest_phone'] = 'Must contain 10 to 15 digits.';
        }

        $guests = $data['guests'] ?? null;
        if (!is_int($guests) || $guests < 1 || $guests > 20) {
            $errors['guests'] = 'Must be an integer from 1 to 20.';
        }

        $startsAt = self::parseTime($data['starts_at'] ?? null);
        $endsAt = self::parseTime($data['ends_at'] ?? null);
        if ($startsAt === null) {
            $errors['starts_at'] = 'Must be an ISO 8601 date-time.';
        } elseif ($startsAt <= $now) {
            $errors['starts_at'] = 'Must be in the future.';
        }
        if ($endsAt === null) {
            $errors['ends_at'] = 'Must be an ISO 8601 date-time.';
        } elseif ($startsAt !== null) {
            $hours = ($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 3600;
            if ($hours <= 0 || $hours > self::MAX_DURATION_HOURS) {
                $errors['ends_at'] = 'Must be after starts_at, at most ' . self::MAX_DURATION_HOURS . ' hours later.';
            }
        }

        $deposit = $data['deposit_amount'] ?? 0;
        if (!is_int($deposit) || $deposit < 0) {
            $errors['deposit_amount'] = 'Must be a non-negative integer in minor units.';
        }

        $currency = $data['deposit_currency'] ?? 'BYN';
        if (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['deposit_currency'] = 'Must be an ISO 4217 code.';
        }

        if ($errors !== [] || $startsAt === null) {
            throw HttpException::unprocessable('Validation failed.', $errors);
        }

        /** @var int $tableId */
        /** @var int $guests */
        /** @var int $deposit */
        /** @var string $currency */
        return new self($tableId, $name, (string) $phone, $guests, $startsAt, $endsAt, $deposit, $currency);
    }

    private static function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        if ($time === false) {
            return null;
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
