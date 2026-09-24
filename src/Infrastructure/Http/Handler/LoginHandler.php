<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler;

use App\Application\User\AuthenticateUser;
use App\Domain\User\InvalidCredentials;
use App\Infrastructure\Http\JsonResponse;
use App\Infrastructure\Http\Request;
use Throwable;

final class LoginHandler
{
    public function __construct(
        private AuthenticateUser $authenticateUser
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if ($request->hasInvalidJson()) {
            return new JsonResponse(
                400,
                [
                    'error' => 'Invalid JSON body.',
                ]
            );
        }

        $body = $request->body();

        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if (
            $email === ''
            || $password === ''
        ) {
            return new JsonResponse(
                422,
                [
                    'error' => 'email and password are required.',
                ]
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(
                422,
                [
                    'error' => 'Invalid email.',
                ]
            );
        }

        try {
            $result = $this->authenticateUser->execute(
                $email,
                $password
            );

            return new JsonResponse(
                200,
                [
                    'user' => [
                        'id' => $result['user_public_id'],
                        'name' => $result['name'],
                        'email' => $result['email'],
                    ],
                ]
            );
        } catch (InvalidCredentials) {
            return new JsonResponse(
                401,
                [
                    'error' => 'Invalid credentials.',
                ]
            );
        } catch (Throwable) {
            return new JsonResponse(
                500,
                [
                    'error' => 'Internal server error.',
                ]
            );
        }
    }
}
