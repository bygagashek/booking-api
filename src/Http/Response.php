<?php

declare(strict_types=1);

namespace BookingApi\Http;

final class Response
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $data = [],
        public readonly array $headers = [],
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->data, [$name => $value] + $this->headers);
    }

    public function body(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body();
    }
}
