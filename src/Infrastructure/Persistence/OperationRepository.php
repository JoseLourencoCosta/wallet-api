<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use PDO;

final class OperationRepository
{
    public function __construct(
        private PDO $connection
    ) {}

    public function create(
        string $publicId,
        string $type,
        string $status,
        string $amount,
        ?int $sourceAccountId,
        ?int $destinationAccountId,
        string $actorType,
        ?int $actorId,
        ?string $authorizationMethod,
        ?string $referenceId = null,
        ?string $idempotencyKey = null,
        ?int $reversalOfOperationId = null
    ): int {
        $statement = $this->connection->prepare(
            '
            INSERT INTO operations (
                public_id,
                type,
                status,
                amount,
                source_account_id,
                destination_account_id,
                actor_type,
                actor_id,
                authorization_method,
                reference_id,
                idempotency_key,
                reversal_of_operation_id
            ) VALUES (
                :public_id,
                :type,
                :status,
                :amount,
                :source_account_id,
                :destination_account_id,
                :actor_type,
                :actor_id,
                :authorization_method,
                :reference_id,
                :idempotency_key,
                :reversal_of_operation_id
            )
            '
        );

        $statement->execute([
            'public_id' => $publicId,
            'type' => $type,
            'status' => $status,
            'amount' => $amount,
            'source_account_id' => $sourceAccountId,
            'destination_account_id' => $destinationAccountId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'authorization_method' => $authorizationMethod,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'reversal_of_operation_id' => $reversalOfOperationId,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function findByIdempotencyKey(
        string $idempotencyKey
    ): ?array {
        $statement = $this->connection->prepare(
            '
        SELECT
            id,
            public_id,
            type,
            status,
            amount,
            source_account_id,
            destination_account_id,
            actor_type,
            actor_id,
            authorization_method,
            reference_id,
            idempotency_key,
            created_at,
            completed_at
        FROM operations
        WHERE idempotency_key = :idempotency_key
        '
        );

        $statement->execute([
            'idempotency_key' => $idempotencyKey,
        ]);

        $operation = $statement->fetch();

        if ($operation === false) {
            return null;
        }

        return $operation;
    }
}
