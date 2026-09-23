<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class SplitPaymentRepository
{
    public function __construct(
        private PDO $connection
    ) {}

    public function create(
        string $publicId,
        int $operationId,
        string $grossAmount,
        string $supplierAmount,
        string $cbsAmount,
        string $ibsAmount,
        string $status,
        ?string $referenceId = null
    ): int {
        $statement = $this->connection->prepare(
            '
            INSERT INTO split_payments (
                public_id,
                operation_id,
                gross_amount,
                supplier_amount,
                cbs_amount,
                ibs_amount,
                status,
                reference_id,
                completed_at
            ) VALUES (
                :public_id,
                :operation_id,
                :gross_amount,
                :supplier_amount,
                :cbs_amount,
                :ibs_amount,
                :status,
                :reference_id,
                CURRENT_TIMESTAMP
            )
            '
        );

        $statement->execute([
            'public_id' => $publicId,
            'operation_id' => $operationId,
            'gross_amount' => $grossAmount,
            'supplier_amount' => $supplierAmount,
            'cbs_amount' => $cbsAmount,
            'ibs_amount' => $ibsAmount,
            'status' => $status,
            'reference_id' => $referenceId,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findByOperationId(int $operationId): ?array
    {
        $statement = $this->connection->prepare(
            '
            SELECT
                id,
                public_id,
                operation_id,
                gross_amount,
                supplier_amount,
                cbs_amount,
                ibs_amount,
                status,
                reference_id,
                created_at,
                completed_at
            FROM split_payments
            WHERE operation_id = :operation_id
            LIMIT 1
            '
        );

        $statement->execute([
            'operation_id' => $operationId,
        ]);

        $splitPayment = $statement->fetch();

        if ($splitPayment === false) {
            return null;
        }

        return $splitPayment;
    }
}
