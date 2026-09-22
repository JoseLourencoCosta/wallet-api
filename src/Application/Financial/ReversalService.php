<?php

declare(strict_types=1);

namespace App\Application\Financial;

use App\Application\Security\PasswordTransactionAuthorizer;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\OperationRepository;
use App\Support\Identifier\PublicIdGenerator;
use PDO;
use RuntimeException;
use Throwable;

final class ReversalService
{
    public function __construct(
        private PDO $connection,
        private AccountRepository $accountRepository,
        private OperationRepository $operationRepository,
        private LedgerEntryRepository $ledgerEntryRepository,
        private PublicIdGenerator $publicIdGenerator,
        private PasswordTransactionAuthorizer $transactionAuthorizer
    ) {}

    public function execute(
        int $operationId,
        int $actorId,
        string $password,
        string $idempotencyKey
    ): array {
        $existingOperation = $this->operationRepository
            ->findByIdempotencyKey($idempotencyKey);

        if ($existingOperation !== null) {
            if (
                $existingOperation['type'] !== 'REVERSAL'
                || (int) $existingOperation['reversal_of_operation_id'] !== $operationId
                || (int) $existingOperation['actor_id'] !== $actorId
            ) {
                throw new RuntimeException(
                    'Idempotency key already used for another operation.'
                );
            }
            if (
                !$this->transactionAuthorizer
                    ->authorize($actorId, $password)
            ) {
                throw new RuntimeException(
                    'Invalid transaction authorization.'
                );
            }

            return [
                'operation_id' => (int) $existingOperation['id'],
                'operation_public_id' => $existingOperation['public_id'],
                'reversal_of_operation_id' => $operationId,
                'amount' => (string) $existingOperation['amount'],
                'idempotent_replay' => true,
            ];
        }

        $this->connection->beginTransaction();

        try {
            $originalOperation = $this->operationRepository
                ->findByIdForUpdate($operationId);

            $existingReversal = $this->operationRepository
                ->findReversalByOriginalOperationId($operationId);

            if ($existingReversal !== null) {
                throw new RuntimeException(
                    'Operation already reversed.'
                );
            }

            if ($originalOperation['type'] !== 'TRANSFER') {
                throw new RuntimeException(
                    'Only transfer operations can be reversed.'
                );
            }

            if ($originalOperation['status'] !== 'COMPLETED') {
                throw new RuntimeException(
                    'Only completed operations can be reversed.'
                );
            }

            $sourceAccountId = (int) $originalOperation['source_account_id'];
            $destinationAccountId = (int) $originalOperation['destination_account_id'];

            $accounts = $this->accountRepository->findTwoForUpdate(
                $sourceAccountId,
                $destinationAccountId
            );

            $sourceAccount = $accounts[$sourceAccountId];
            $destinationAccount = $accounts[$destinationAccountId];

            if ((int) $sourceAccount['user_id'] !== $actorId) {
                throw new RuntimeException(
                    'User is not authorized to reverse this operation.'
                );
            }

            if (
                !$this->transactionAuthorizer
                    ->authorize($actorId, $password)
            ) {
                throw new RuntimeException(
                    'Invalid transaction authorization.'
                );
            }

            if (
                $sourceAccount['status'] !== 'ACTIVE'
                || $destinationAccount['status'] !== 'ACTIVE'
            ) {
                throw new RuntimeException(
                    'Account is not active.'
                );
            }

            $amount = (string) $originalOperation['amount'];

            $sourceBalanceBefore = (string) $sourceAccount['balance'];
            $destinationBalanceBefore = (string) $destinationAccount['balance'];

            $amountCents = $this->toCents($amount);
            $sourceBalanceCents = $this->toCents($sourceBalanceBefore);
            $destinationBalanceCents = $this->toCents($destinationBalanceBefore);

            if ($destinationBalanceCents < $amountCents) {
                throw new RuntimeException(
                    'Destination account has insufficient balance for reversal.'
                );
            }

            $sourceBalanceAfter = $this->fromCents(
                $sourceBalanceCents + $amountCents
            );

            $destinationBalanceAfter = $this->fromCents(
                $destinationBalanceCents - $amountCents
            );

            $operationPublicId = $this->publicIdGenerator->generate();

            $reversalOperationId = $this->operationRepository->create(
                $operationPublicId,
                'REVERSAL',
                'COMPLETED',
                $amount,
                $destinationAccountId,
                $sourceAccountId,
                'USER',
                $actorId,
                'PASSWORD',
                null,
                $idempotencyKey,
                $operationId
            );

            $this->accountRepository->updateBalance(
                $sourceAccountId,
                $sourceBalanceAfter
            );

            $this->accountRepository->updateBalance(
                $destinationAccountId,
                $destinationBalanceAfter
            );

            $this->ledgerEntryRepository->create(
                $this->publicIdGenerator->generate(),
                $reversalOperationId,
                $sourceAccountId,
                'CREDIT',
                $amount,
                $sourceBalanceBefore,
                $sourceBalanceAfter
            );

            $this->ledgerEntryRepository->create(
                $this->publicIdGenerator->generate(),
                $reversalOperationId,
                $destinationAccountId,
                'DEBIT',
                $amount,
                $destinationBalanceBefore,
                $destinationBalanceAfter
            );

            $this->connection->commit();

            return [
                'operation_id' => $reversalOperationId,
                'operation_public_id' => $operationPublicId,
                'reversal_of_operation_id' => $operationId,
                'amount' => $amount,
                'source_balance_before' => $sourceBalanceBefore,
                'source_balance_after' => $sourceBalanceAfter,
                'destination_balance_before' => $destinationBalanceBefore,
                'destination_balance_after' => $destinationBalanceAfter,
                'idempotent_replay' => false,
            ];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    private function toCents(string $amount): int
    {
        if (!preg_match('/^\d+\.\d{2}$/', $amount)) {
            throw new RuntimeException('Invalid monetary value.');
        }

        [$whole, $decimal] = explode('.', $amount);

        return ((int) $whole * 100) + (int) $decimal;
    }

    private function fromCents(int $amount): string
    {
        return sprintf(
            '%d.%02d',
            intdiv($amount, 100),
            $amount % 100
        );
    }
}
