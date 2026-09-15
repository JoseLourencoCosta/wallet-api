<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class UserRepository
{
    public function __construct(
        private PDO $connection
    ) {
    }

    public function create(
        string $publicId,
        string $name,
        string $email,
        string $passwordHash
    ): int {
        $statement = $this->connection->prepare(
            '
            INSERT INTO users (
                public_id,
                name,
                email,
                password_hash
            ) VALUES (
                :public_id,
                :name,
                :email,
                :password_hash
            )
            '
        );

        $statement->execute([
            'public_id' => $publicId,
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findPasswordHashById(int $userId): string
{
    $statement = $this->connection->prepare(
        '
        SELECT password_hash
        FROM users
        WHERE id = :id
        '
    );

    $statement->execute([
        'id' => $userId,
    ]);

    $passwordHash = $statement->fetchColumn();

    if ($passwordHash === false) {
        throw new \RuntimeException('User not found.');
    }

    return (string) $passwordHash;
}
}