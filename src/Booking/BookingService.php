<?php

declare(strict_types=1);

namespace BookingApi\Booking;

use BookingApi\Database;
use BookingApi\Http\HttpException;
use PDO;

final class BookingService
{
    private const DB_TIME = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(CreateBooking $input): array
    {
        return Database::transaction($this->pdo, function () use ($input): array {
            // Locking the table row serializes concurrent bookings of the same table:
            // the second request waits here and then sees the first one's booking.
            $stmt = $this->pdo->prepare('SELECT id, seats FROM restaurant_tables WHERE id = ? FOR UPDATE');
            $stmt->execute([$input->tableId]);
            $table = $stmt->fetch();
            if ($table === false) {
                throw HttpException::unprocessable('Validation failed.', ['table_id' => 'Table does not exist.']);
            }
            if ($input->guests > (int) $table['seats']) {
                throw HttpException::unprocessable('Validation failed.', ['guests' => "Table has {$table['seats']} seats."]);
            }

            $stmt = $this->pdo->prepare(
                "SELECT id FROM bookings
                 WHERE table_id = ? AND status <> 'cancelled' AND starts_at < ? AND ends_at > ?
                 LIMIT 1",
            );
            $stmt->execute([
                $input->tableId,
                $input->endsAt->format(self::DB_TIME),
                $input->startsAt->format(self::DB_TIME),
            ]);
            if ($stmt->fetchColumn() !== false) {
                throw HttpException::conflict('The table is already booked for this time.');
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO bookings
                    (table_id, guest_name, guest_phone, guests, starts_at, ends_at, deposit_amount, deposit_currency, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $stmt->execute([
                $input->tableId,
                $input->guestName,
                $input->guestPhone,
                $input->guests,
                $input->startsAt->format(self::DB_TIME),
                $input->endsAt->format(self::DB_TIME),
                $input->depositAmount,
                $input->depositCurrency,
                $input->depositAmount > 0 ? 'pending_payment' : 'confirmed',
            ]);

            return $this->find((int) $this->pdo->lastInsertId());
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        return $this->find($id);
    }

    /**
     * Cancelling twice is not an error: the second call returns the same cancelled booking.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $id): array
    {
        return Database::transaction($this->pdo, function () use ($id): array {
            $booking = $this->find($id, forUpdate: true);
            if ($booking['status'] !== 'cancelled') {
                $this->pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$id]);
                $booking['status'] = 'cancelled';
            }

            return $booking;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function find(int $id, bool $forUpdate = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, table_id, guest_name, guest_phone, guests, starts_at, ends_at,
                    deposit_amount, deposit_currency, status
             FROM bookings WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw HttpException::notFound('Booking not found.');
        }

        return self::present($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'table_id' => (int) $row['table_id'],
            'guest_name' => $row['guest_name'],
            'guest_phone' => $row['guest_phone'],
            'guests' => (int) $row['guests'],
            'starts_at' => str_replace(' ', 'T', (string) $row['starts_at']) . 'Z',
            'ends_at' => str_replace(' ', 'T', (string) $row['ends_at']) . 'Z',
            'deposit' => ['amount' => (int) $row['deposit_amount'], 'currency' => $row['deposit_currency']],
            'status' => $row['status'],
        ];
    }
}
