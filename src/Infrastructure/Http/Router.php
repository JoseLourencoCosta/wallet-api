<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Http\Handler\RegisterUserHandler;
use App\Infrastructure\Http\Handler\LoginHandler;
use Closure;

final class Router
{
    public function __construct(
        private Closure $registerUserHandlerFactory,
        private Closure $loginHandlerFactory
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

        if (
            $request->method() === 'POST'
            && $request->path() === '/login'
        ) {
            $loginHandler = (
                $this->loginHandlerFactory
            )();

            return $loginHandler->handle(
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
