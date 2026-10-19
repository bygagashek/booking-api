<?php

declare(strict_types=1);

namespace BookingApi\Payment;

use BookingApi\Database;
use BookingApi\Http\HttpException;
use PDO;
use PDOException;

/**
 * Applies a "payment succeeded" event to a booking deposit.
 *
 * Payment providers deliver at least once, so the same event can arrive several times,
 * even concurrently. Each event is applied at most once: the booking row is locked, and
 * the event id is the primary key of payment_events.
 *
 * Events that cannot be applied (wrong amount or currency, booking cancelled or already
 * paid) are stored as "rejected" for manual review. They are answered with 200, because
 * a retry would not change the outcome.
 */
final class PaymentWebhookHandler
{
    private const DUPLICATE_KEY = 1062;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $event
     * @return array{event_id: string, result: string, reason?: string, duplicate?: bool}
     */
    public function handle(array $event): array
    {
        $eventId = $event['event_id'] ?? null;
        $bookingId = $event['booking_id'] ?? null;
        $amount = $event['amount'] ?? null;
        $currency = $event['currency'] ?? null;
        $status = $event['status'] ?? null;

        if (!is_string($eventId) || $eventId === '' || strlen($eventId) > 64
            || !is_int($bookingId) || !is_int($amount) || $amount < 0 || !is_string($currency)) {
            throw HttpException::badRequest('Malformed payment event.');
        }
        if ($status !== 'succeeded') {
            return ['event_id' => $eventId, 'result' => 'ignored'];
        }

        try {
            return Database::transaction($this->pdo, function () use ($eventId, $bookingId, $amount, $currency): array {
                $stmt = $this->pdo->prepare(
                    'SELECT status, deposit_amount, deposit_currency FROM bookings WHERE id = ? FOR UPDATE',
                );
                $stmt->execute([$bookingId]);
                $booking = $stmt->fetch();

                $previous = $this->findEvent($eventId);
                if ($previous !== null) {
                    return $previous + ['duplicate' => true];
                }

                $reason = match (true) {
                    $booking === false => 'Booking not found.',
                    $booking['status'] === 'cancelled' => 'Booking is cancelled, refund required.',
                    $booking['status'] === 'confirmed' => 'Booking is already paid, possible double payment.',
                    (int) $booking['deposit_amount'] !== $amount => "Amount mismatch: expected {$booking['deposit_amount']}, got $amount.",
                    $booking['deposit_currency'] !== $currency => "Currency mismatch: expected {$booking['deposit_currency']}, got $currency.",
                    default => null,
                };

                if ($reason === null) {
                    $this->pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?")->execute([$bookingId]);
                }

                $this->pdo->prepare(
                    'INSERT INTO payment_events (event_id, booking_id, amount, currency, result, reason) VALUES (?, ?, ?, ?, ?, ?)',
                )->execute([$eventId, $bookingId, $amount, $currency, $reason === null ? 'applied' : 'rejected', $reason]);

                return $reason === null
                    ? ['event_id' => $eventId, 'result' => 'applied']
                    : ['event_id' => $eventId, 'result' => 'rejected', 'reason' => $reason];
            });
        } catch (PDOException $e) {
            // A concurrent delivery of the same event (unknown booking, so nothing was locked) won the race.
            if (($e->errorInfo[1] ?? null) === self::DUPLICATE_KEY && ($previous = $this->findEvent($eventId)) !== null) {
                return $previous + ['duplicate' => true];
            }
            throw $e;
        }
    }

    /**
     * @return array{event_id: string, result: string, reason?: string}|null
     */
    private function findEvent(string $eventId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT result, reason FROM payment_events WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $result = ['event_id' => $eventId, 'result' => (string) $row['result']];
        if ($row['reason'] !== null) {
            $result['reason'] = (string) $row['reason'];
        }

        return $result;
    }
}
