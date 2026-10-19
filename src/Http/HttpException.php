<?php

declare(strict_types=1);

namespace BookingApi\Http;

use RuntimeException;

final class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $errors field => message
     */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message): self
    {
        return new self(400, $message);
    }

    public static function unauthorized(string $message): self
    {
        return new self(401, $message);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self(404, $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, $message);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function unprocessable(string $message, array $errors = []): self
    {
        return new self(422, $message, $errors);
    }

    public function toResponse(): Response
    {
        $data = ['error' => $this->getMessage()];
        if ($this->errors !== []) {
            $data['fields'] = $this->errors;
        }

        return new Response($this->status, $data);
    }
}
