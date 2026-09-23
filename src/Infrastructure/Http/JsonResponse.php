<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

final class JsonResponse
{
    public function __construct(
        private int $statusCode,
        private array $body
    ) {}

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $this->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
