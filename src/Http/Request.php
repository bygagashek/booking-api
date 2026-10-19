<?php

declare(strict_types=1);

namespace BookingApi\Http;

use JsonException;

final class Request
{
    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            $headers,
            (string) file_get_contents('php://input'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::badRequest('Request body must be valid JSON.');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw HttpException::badRequest('Request body must be a JSON object.');
        }

        return $data;
    }
}
