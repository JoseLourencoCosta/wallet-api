<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private array $body,
        private bool $invalidJson
    ) {}

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $path = parse_url(
            $_SERVER['REQUEST_URI'] ?? '/',
            PHP_URL_PATH
        );

        $rawBody = file_get_contents('php://input');

        $body = [];
        $invalidJson = false;

        if ($rawBody !== false && trim($rawBody) !== '') {
            $decoded = json_decode(
                $rawBody,
                true
            );

            if (
                json_last_error() !== JSON_ERROR_NONE
                || !is_array($decoded)
            ) {
                $invalidJson = true;
            } else {
                $body = $decoded;
            }
        }

        return new self(
            $method,
            $path ?: '/',
            $body,
            $invalidJson
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function body(): array
    {
        return $this->body;
    }

    public function hasInvalidJson(): bool
    {
        return $this->invalidJson;
    }
}
