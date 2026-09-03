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

    public function findBalanceById(int $accountId): string
    {
    $statement = $this->connection->prepare(
        '
        SELECT balance
        FROM accounts
        WHERE id = :id
        '
    );

    $statement->execute([
        'id' => $accountId,
    ]);

    $balance = $statement->fetchColumn();

    if ($balance === false) {
        throw new \RuntimeException('Account not found.');
    }

    return (string) $balance;
    }

    public function updateBalance(
    int $accountId,
    string $balance
    ): void {
    $statement = $this->connection->prepare(
        '
        UPDATE accounts
        SET balance = :balance
        WHERE id = :id
        '
    );

    $statement->execute([
        'balance' => $balance,
        'id' => $accountId,
    ]);
    }

    public function findBalanceForUpdate(int $accountId): string
    {
    $statement = $this->connection->prepare(
        '
        SELECT balance
        FROM accounts
        WHERE id = :id
        FOR UPDATE
        '
    );

    $statement->execute([
        'id' => $accountId,
    ]);

    $balance = $statement->fetchColumn();

    if ($balance === false) {
        throw new \RuntimeException('Account not found.');
    }

    return (string) $balance;
    }

    public function findTwoForUpdate(
    int $firstAccountId,
    int $secondAccountId
): array {
    $accountIds = [
        $firstAccountId,
        $secondAccountId,
    ];

    sort($accountIds);

    $statement = $this->connection->prepare(
        '
        SELECT
            id,
            user_id,
            balance,
            status
        FROM accounts
        WHERE id IN (:first_id, :second_id)
        ORDER BY id
        FOR UPDATE
        '
    );

    $statement->execute([
        'first_id' => $accountIds[0],
        'second_id' => $accountIds[1],
    ]);

    $accounts = $statement->fetchAll();

    if (count($accounts) !== 2) {
        throw new \RuntimeException(
            'One or more accounts were not found.'
        );
    }

    $result = [];

    foreach ($accounts as $account) {
        $result[(int) $account['id']] = $account;
    }

    return $result;
 }
}