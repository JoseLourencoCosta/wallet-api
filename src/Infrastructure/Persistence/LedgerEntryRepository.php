<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class LedgerEntryRepository
{
    public function __construct(
        private PDO $connection
    ) {
    }

    public function create(
        string $publicId,
        int $operationId,
        int $accountId,
        string $entryType,
        string $amount,
        string $balanceBefore,
        string $balanceAfter
    ): int {
        $statement = $this->connection->prepare(
            '
            INSERT INTO ledger_entries (
                public_id,
                operation_id,
                account_id,
                entry_type,
                amount,
                balance_before,
                balance_after
            ) VALUES (
                :public_id,
                :operation_id,
                :account_id,
                :entry_type,
                :amount,
                :balance_before,
                :balance_after
            )
            '
        );

        $statement->execute([
            'public_id' => $publicId,
            'operation_id' => $operationId,
            'account_id' => $accountId,
            'entry_type' => $entryType,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
        ]);

        return (int) $this->connection->lastInsertId();
    }
}