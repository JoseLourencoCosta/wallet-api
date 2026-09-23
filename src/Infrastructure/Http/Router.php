<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Http\Handler\RegisterUserHandler;
use Closure;

final class Router
{
    public function __construct(
        private Closure $registerUserHandlerFactory
    ) {}

    public function dispatch(Request $request): JsonResponse
    {
        if (
            $request->method() === 'GET'
            && $request->path() === '/health'
        ) {
            return new JsonResponse(
                200,
                [
                    'status' => 'ok',
                ]
            );
        }

        if (
            $request->method() === 'POST'
            && $request->path() === '/users'
        ) {
            $registerUserHandler = (
                $this->registerUserHandlerFactory
            )();

            return $registerUserHandler->handle(
                $request
            );
        }

        return new JsonResponse(
            404,
            [
                'error' => 'Route not found.',
            ]
        );
    }
}
