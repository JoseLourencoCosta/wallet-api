<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class AccountRepository
{
    public function __construct(
        private PDO $connection
    ) {
    }

    public function create(
        string $publicId,
        int $userId,
        string $agency,
        string $accountNumber,
        string $accountDigit
    ): int {
        $statement = $this->connection->prepare(
            '
            INSERT INTO accounts (
                public_id,
                user_id,
                agency,
                account_number,
                account_digit
            ) VALUES (
                :public_id,
                :user_id,
                :agency,
                :account_number,
                :account_digit
            )
            '
        );

        $statement->execute([
            'public_id' => $publicId,
            'user_id' => $userId,
            'agency' => $agency,
            'account_number' => $accountNumber,
            'account_digit' => $accountDigit,
        ]);

        return (int) $this->connection->lastInsertId();
    }
}