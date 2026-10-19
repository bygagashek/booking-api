<?php

declare(strict_types=1);

namespace BookingApi\Idempotency;

use BookingApi\Http\HttpException;
use BookingApi\Http\Response;
use PDO;
use PDOException;
use Throwable;

/**
 * Makes a non-idempotent operation (POST) safe to retry.
 *
 * The client sends an Idempotency-Key with the request. The first request with a key
 * reserves it and stores its response. A retry with the same key and body gets the
 * stored response instead of a second booking. The same key with a different body is
 * rejected, and so is a retry that arrives while the first request is still running.
 */
final class IdempotencyStore
{
    private const DUPLICATE_KEY = 1062;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param callable(): Response $operation
     */
    public function run(?string $key, string $requestBody, callable $operation): Response
    {
        if ($key === null || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key) !== 1) {
            throw HttpException::badRequest('Idempotency-Key header is required: 8-64 characters [A-Za-z0-9_-].');
        }
        $hash = hash('sha256', $requestBody);

        if (!$this->reserve($key, $hash)) {
            return $this->replay($key, $hash);
        }

        try {
            $response = $operation();
        } catch (HttpException $e) {
            // Client errors are deterministic: the same request will fail the same way, so remember the result.
            $response = $e->toResponse();
        } catch (Throwable $e) {
            // Server error: release the key so the client can retry.
            $this->pdo->prepare('DELETE FROM idempotency_keys WHERE idempotency_key = ?')->execute([$key]);
            throw $e;
        }

        if ($response->status >= 500) {
            $this->pdo->prepare('DELETE FROM idempotency_keys WHERE idempotency_key = ?')->execute([$key]);

            return $response;
        }

        $this->pdo->prepare(
            "UPDATE idempotency_keys SET status = 'done', response_code = ?, response_body = ? WHERE idempotency_key = ?",
        )->execute([$response->status, $response->body(), $key]);

        return $response;
    }

    private function reserve(string $key, string $hash): bool
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO idempotency_keys (idempotency_key, request_hash, status) VALUES (?, ?, 'processing')",
            )->execute([$key, $hash]);

            return true;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === self::DUPLICATE_KEY) {
                return false;
            }
            throw $e;
        }
    }

    private function replay(string $key, string $hash): Response
    {
        $stmt = $this->pdo->prepare(
            'SELECT request_hash, status, response_code, response_body FROM idempotency_keys WHERE idempotency_key = ?',
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if ($row === false) {
            // The first request failed with a server error and released the key a moment ago.
            throw HttpException::conflict('A request with this Idempotency-Key has just failed, retry it.');
        }
        if (!hash_equals($row['request_hash'], $hash)) {
            throw HttpException::unprocessable('This Idempotency-Key was already used with a different request body.');
        }
        if ($row['status'] === 'processing') {
            throw HttpException::conflict('A request with this Idempotency-Key is still being processed.');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $row['response_body'], true, 32, JSON_THROW_ON_ERROR);

        return (new Response((int) $row['response_code'], $data))->withHeader('Idempotent-Replayed', 'true');
    }
}
