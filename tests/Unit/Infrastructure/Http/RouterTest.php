<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testHealthRouteReturnsOkWithoutBuildingHandlers(): void
    {
        $registerFactoryCalled = false;
        $loginFactoryCalled = false;

        $router = new Router(
            static function () use (&$registerFactoryCalled) {
                $registerFactoryCalled = true;

                throw new \RuntimeException(
                    'Register handler factory should not be called.'
                );
            },
            static function () use (&$loginFactoryCalled) {
                $loginFactoryCalled = true;

                throw new \RuntimeException(
                    'Login handler factory should not be called.'
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
            $registerFactoryCalled
        );

        self::assertFalse(
            $loginFactoryCalled
        );
    }

    public function testUnknownRouteReturnsNotFoundWithoutBuildingHandlers(): void
    {
        $registerFactoryCalled = false;
        $loginFactoryCalled = false;

        $router = new Router(
            static function () use (&$registerFactoryCalled) {
                $registerFactoryCalled = true;

                throw new \RuntimeException(
                    'Register handler factory should not be called.'
                );
            },
            static function () use (&$loginFactoryCalled) {
                $loginFactoryCalled = true;

                throw new \RuntimeException(
                    'Login handler factory should not be called.'
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
            $registerFactoryCalled
        );

        self::assertFalse(
            $loginFactoryCalled
        );
    }
}
