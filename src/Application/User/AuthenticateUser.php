<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Domain\User\InvalidCredentials;
use App\Infrastructure\Persistence\UserRepository;

final class AuthenticateUser
{
    public function __construct(
        private UserRepository $userRepository
    ) {}

    public function execute(
        string $email,
        string $password
    ): array {
        $user = $this->userRepository->findByEmail(
            $email
        );

        if (
            $user === null
            || !password_verify(
                $password,
                $user['password_hash']
            )
        ) {
            throw new InvalidCredentials(
                'Invalid credentials.'
            );
        }

        return [
            'user_id' => $user['id'],
            'user_public_id' => $user['public_id'],
            'name' => $user['name'],
            'email' => $user['email'],
        ];
    }
}
