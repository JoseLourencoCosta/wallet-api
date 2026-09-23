<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Handler;

use App\Application\User\RegisterUser;
use App\Infrastructure\Http\JsonResponse;
use App\Infrastructure\Http\Request;
use PDOException;
use Throwable;

final class RegisterUserHandler
{
    public function __construct(
        private RegisterUser $registerUser
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

        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if (
            $name === ''
            || $email === ''
            || $password === ''
        ) {
            return new JsonResponse(
                422,
                [
                    'error' => 'name, email and password are required.',
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
            $result = $this->registerUser->execute(
                $name,
                $email,
                $password
            );

            return new JsonResponse(
                201,
                [
                    'user' => [
                        'id' => $result['user_public_id'],
                        'name' => $name,
                        'email' => $email,
                    ],
                    'account' => [
                        'id' => $result['account_public_id'],
                        'agency' => $result['agency'],
                        'number' => $result['account_number'],
                        'digit' => $result['account_digit'],
                    ],
                ]
            );
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return new JsonResponse(
                    409,
                    [
                        'error' => 'Email already registered.',
                    ]
                );
            }

            return new JsonResponse(
                500,
                [
                    'error' => 'Internal server error.',
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
