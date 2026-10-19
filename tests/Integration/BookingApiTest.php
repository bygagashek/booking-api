<?php

declare(strict_types=1);

namespace BookingApi\Tests\Integration;

use BookingApi\App;
use BookingApi\Database;
use BookingApi\Http\Request;
use BookingApi\Http\Response;
use BookingApi\Payment\SignatureVerifier;
use DateTimeImmutable;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests against a real MariaDB (row locks and duplicate-key errors cannot be faked).
 * Configure with DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD; skipped when the database is unreachable.
 */
final class BookingApiTest extends TestCase
{
    private const NOW = '2030-01-01T12:00:00+00:00';
    private const SECRET = 'test-secret';

    private PDO $pdo;
    private App $app;
    private SignatureVerifier $signer;
    private int $keySeq = 0;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::fromEnv();
        } catch (PDOException $e) {
            self::markTestSkipped('MariaDB is not available: ' . $e->getMessage());
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['payment_events', 'idempotency_keys', 'bookings', 'restaurant_tables'] as $table) {
            $this->pdo->exec("TRUNCATE TABLE $table");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->pdo->exec("INSERT INTO restaurant_tables (id, name, seats) VALUES (1, 'Window', 2), (2, 'Hall', 6)");

        $this->signer = new SignatureVerifier(self::SECRET);
        $this->app = new App($this->pdo, $this->signer, static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW));
    }

    public function testCreateAndGetBooking(): void
    {
        $created = $this->createBooking();

        self::assertSame(201, $created->status);
        self::assertSame('confirmed', $created->data['status'], 'no deposit, confirmed at once');
        self::assertSame('2030-01-02T18:00:00Z', $created->data['starts_at']);

        $fetched = $this->app->handle(new Request('GET', '/bookings/' . $created->data['id']));
        self::assertSame(200, $fetched->status);
        self::assertSame($created->data, $fetched->data);
    }

    public function testOverlappingBookingIsRejected(): void
    {
        $this->createBooking();

        $overlap = $this->createBooking(['starts_at' => '2030-01-02T19:00:00+00:00', 'ends_at' => '2030-01-02T21:00:00+00:00']);
        self::assertSame(409, $overlap->status);

        $adjacent = $this->createBooking(['starts_at' => '2030-01-02T20:00:00+00:00', 'ends_at' => '2030-01-02T22:00:00+00:00']);
        self::assertSame(201, $adjacent->status, 'a booking may start exactly when the previous one ends');

        $otherTable = $this->createBooking(['table_id' => 2]);
        self::assertSame(201, $otherTable->status);
    }

    public function testCancelledBookingFreesTheSlot(): void
    {
        $first = $this->createBooking();
        $cancel = $this->app->handle(new Request('POST', "/bookings/{$first->data['id']}/cancel"));
        self::assertSame('cancelled', $cancel->data['status']);

        self::assertSame(201, $this->createBooking()->status);
    }

    public function testTooManyGuestsForTable(): void
    {
        $response = $this->createBooking(['guests' => 3]);

        self::assertSame(422, $response->status);
        self::assertArrayHasKey('guests', $response->data['fields']);
    }

    public function testRetryWithSameKeyReturnsSameBooking(): void
    {
        $first = $this->createBooking([], 'retry-key-1');
        $retry = $this->createBooking([], 'retry-key-1');

        self::assertSame(201, $retry->status);
        self::assertSame($first->data, $retry->data);
        self::assertSame('true', $retry->headers['Idempotent-Replayed'] ?? null);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn());
    }

    public function testSameKeyWithDifferentBodyIsRejected(): void
    {
        $this->createBooking([], 'reused-key-1');

        self::assertSame(422, $this->createBooking(['table_id' => 2], 'reused-key-1')->status);
    }

    public function testKeyIsRequired(): void
    {
        $response = $this->app->handle(new Request('POST', '/bookings', [], $this->bookingJson()));

        self::assertSame(400, $response->status);
    }

    public function testRetryWhileFirstRequestIsRunningIsRejected(): void
    {
        $this->pdo->prepare("INSERT INTO idempotency_keys (idempotency_key, request_hash, status) VALUES (?, ?, 'processing')")
            ->execute(['in-flight-1', hash('sha256', $this->bookingJson())]);

        self::assertSame(409, $this->createBooking([], 'in-flight-1')->status);
    }

    /**
     * Proves that creation takes a row lock on the table: while another transaction holds it,
     * a concurrent booking of the same table waits instead of reading stale data.
     */
    public function testConcurrentBookingWaitsForTableLock(): void
    {
        $other = Database::fromEnv();
        $other->beginTransaction();
        $other->query('SELECT id FROM restaurant_tables WHERE id = 1 FOR UPDATE')->fetchAll();

        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = $this->createBooking();
        $other->rollBack();

        self::assertSame(500, $blocked->status, 'lock wait timeout');
        self::assertSame(201, $this->createBooking()->status, 'the key was released, the retry succeeds');
    }

    public function testPaymentConfirmsBooking(): void
    {
        $booking = $this->createBooking(['deposit_amount' => 5000]);
        self::assertSame('pending_payment', $booking->data['status']);

        $response = $this->sendWebhook($this->event($booking->data['id'], 5000));

        self::assertSame(200, $response->status);
        self::assertSame('applied', $response->data['result']);
        self::assertSame('confirmed', $this->bookingStatus($booking->data['id']));
    }

    public function testRedeliveredEventIsAppliedOnce(): void
    {
        $booking = $this->createBooking(['deposit_amount' => 5000]);
        $event = $this->event($booking->data['id'], 5000);

        $this->sendWebhook($event);
        $again = $this->sendWebhook($event);

        self::assertSame('applied', $again->data['result']);
        self::assertTrue($again->data['duplicate']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM payment_events')->fetchColumn());
    }

    public function testSecondPaymentForPaidBookingIsFlagged(): void
    {
        $booking = $this->createBooking(['deposit_amount' => 5000]);
        $this->sendWebhook($this->event($booking->data['id'], 5000, 'evt_first'));

        $second = $this->sendWebhook($this->event($booking->data['id'], 5000, 'evt_second'));

        self::assertSame('rejected', $second->data['result']);
        self::assertStringContainsString('double payment', $second->data['reason']);
    }

    public function testWrongAmountOrCurrencyIsRejected(): void
    {
        $booking = $this->createBooking(['deposit_amount' => 5000]);

        $amount = $this->sendWebhook($this->event($booking->data['id'], 100, 'evt_amount'));
        $currency = $this->sendWebhook($this->event($booking->data['id'], 5000, 'evt_currency', 'USD'));

        self::assertSame('rejected', $amount->data['result']);
        self::assertSame('rejected', $currency->data['result']);
        self::assertSame('pending_payment', $this->bookingStatus($booking->data['id']));
    }

    public function testPaymentForCancelledBookingIsRejected(): void
    {
        $booking = $this->createBooking(['deposit_amount' => 5000]);
        $this->app->handle(new Request('POST', "/bookings/{$booking->data['id']}/cancel"));

        $response = $this->sendWebhook($this->event($booking->data['id'], 5000));

        self::assertSame('rejected', $response->data['result']);
        self::assertSame('cancelled', $this->bookingStatus($booking->data['id']));
    }

    public function testUnsignedWebhookIsRejected(): void
    {
        $booking = $this->createBooking(['deposit_amount' => 5000]);
        $body = json_encode($this->event($booking->data['id'], 5000), JSON_THROW_ON_ERROR);

        $response = $this->app->handle(new Request('POST', '/webhooks/payments', [
            'x-timestamp' => (string) (new DateTimeImmutable(self::NOW))->getTimestamp(),
            'x-signature' => 'sha256=' . str_repeat('0', 64),
        ], $body));

        self::assertSame(401, $response->status);
        self::assertSame('pending_payment', $this->bookingStatus($booking->data['id']));
    }

    /**
     * @param array<string, mixed> $override
     */
    private function createBooking(array $override = [], ?string $key = null): Response
    {
        $key ??= 'test-key-' . ++$this->keySeq;

        return $this->app->handle(new Request('POST', '/bookings', ['idempotency-key' => $key], $this->bookingJson($override)));
    }

    /**
     * @param array<string, mixed> $override
     */
    private function bookingJson(array $override = []): string
    {
        return json_encode($override + [
            'table_id' => 1,
            'guest_name' => 'Anna',
            'guest_phone' => '+375291234567',
            'guests' => 2,
            'starts_at' => '2030-01-02T18:00:00+00:00',
            'ends_at' => '2030-01-02T20:00:00+00:00',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(int $bookingId, int $amount, string $eventId = 'evt_1', string $currency = 'BYN'): array
    {
        return [
            'event_id' => $eventId,
            'booking_id' => $bookingId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'succeeded',
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function sendWebhook(array $event): Response
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (new DateTimeImmutable(self::NOW))->getTimestamp();

        return $this->app->handle(new Request('POST', '/webhooks/payments', [
            'x-timestamp' => (string) $timestamp,
            'x-signature' => $this->signer->sign($timestamp, $body),
        ], $body));
    }

    private function bookingStatus(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM bookings WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }
}
