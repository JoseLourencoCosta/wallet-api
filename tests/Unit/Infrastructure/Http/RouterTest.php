<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testHealthRouteReturnsOkWithoutBuildingUserHandler(): void
    {
        $factoryCalled = false;

        $router = new Router(
            static function () use (&$factoryCalled) {
                $factoryCalled = true;

                throw new \RuntimeException(
                    'Handler factory should not be called.'
                );
            }
        );

        $request = new Request(
            'GET',
            '/health',
            [],
            false
        );

        $response = $router->dispatch($request);

        self::assertSame(
            200,
            $response->statusCode()
        );

        self::assertSame(
            [
                'status' => 'ok',
            ],
            $response->body()
        );

        self::assertFalse(
            $factoryCalled
        );
    }

    public function testUnknownRouteReturnsNotFoundWithoutBuildingUserHandler(): void
    {
        $factoryCalled = false;

        $router = new Router(
            static function () use (&$factoryCalled) {
                $factoryCalled = true;

                throw new \RuntimeException(
                    'Handler factory should not be called.'
                );
            }
        );

        $request = new Request(
            'GET',
            '/unknown',
            [],
            false
        );

        $response = $router->dispatch($request);

        self::assertSame(
            404,
            $response->statusCode()
        );

        self::assertSame(
            [
                'error' => 'Route not found.',
            ],
            $response->body()
        );

        self::assertFalse(
            $factoryCalled
        );
    }
}
