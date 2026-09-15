<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Infrastructure\Persistence\UserRepository;

final class PasswordTransactionAuthorizer
{
    public function __construct(
        private UserRepository $userRepository
    ) {
    }

    public function authorize(
        int $userId,
        string $password
    ): bool {
        $passwordHash = $this->userRepository
            ->findPasswordHashById($userId);

        return password_verify(
            $password,
            $passwordHash
        );
    }
}