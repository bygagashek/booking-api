<?php

declare(strict_types=1);

namespace BookingApi;

use PDO;

final class Database
{
    public static function fromEnv(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_NAME') ?: 'booking',
        );

        $pdo = new PDO($dsn, getenv('DB_USER') ?: 'booking', getenv('DB_PASSWORD') ?: 'booking', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // All DATETIME columns hold UTC.
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function transaction(PDO $pdo, callable $callback): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
