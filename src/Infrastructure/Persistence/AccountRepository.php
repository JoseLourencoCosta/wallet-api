<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class AccountRepository
{
    public function __construct(
        private PDO $connection
    ) {}

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

    public function createSystemAccount(
        string $publicId,
        string $agency,
        string $accountNumber,
        string $accountDigit
    ): int {
        $statement = $this->connection->prepare(
            '
        INSERT INTO accounts (
            public_id,
            user_id,
            account_type,
            agency,
            account_number,
            account_digit
        ) VALUES (
            :public_id,
            NULL,
            :account_type,
            :agency,
            :account_number,
            :account_digit
        )
        '
        );

        $statement->execute([
            'public_id' => $publicId,
            'account_type' => 'SYSTEM',
            'agency' => $agency,
            'account_number' => $accountNumber,
            'account_digit' => $accountDigit,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findSystemAccountByRole(string $systemRole): array
    {
        $statement = $this->connection->prepare(
            '
        SELECT
            id,
            public_id,
            user_id,
            account_type,
            system_role,
            agency,
            account_number,
            balance,
            status
        FROM accounts
        WHERE account_type = :account_type
          AND system_role = :system_role
        LIMIT 1
        '
        );

        $statement->execute([
            'account_type' => 'SYSTEM',
            'system_role' => $systemRole,
        ]);

        $account = $statement->fetch();

        if ($account === false) {
            throw new \RuntimeException(
                'System account not found.'
            );
        }

        return $account;
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

    public function findManyForUpdate(array $accountIds): array
    {
        $accountIds = array_values(
            array_unique(
                array_map('intval', $accountIds)
            )
        );

        sort($accountIds);

        if ($accountIds === []) {
            throw new \InvalidArgumentException(
                'At least one account id is required.'
            );
        }

        $placeholders = [];

        foreach ($accountIds as $index => $accountId) {
            $placeholders[] = ':account_' . $index;
        }

        $statement = $this->connection->prepare(
            sprintf(
                '
            SELECT
                id,
                user_id,
                account_type,
                system_role,
                balance,
                status
            FROM accounts
            WHERE id IN (%s)
            ORDER BY id
            FOR UPDATE
            ',
                implode(', ', $placeholders)
            )
        );

        $parameters = [];

        foreach ($accountIds as $index => $accountId) {
            $parameters['account_' . $index] = $accountId;
        }

        $statement->execute($parameters);

        $accounts = $statement->fetchAll();

        if (count($accounts) !== count($accountIds)) {
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
            account_type,
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
