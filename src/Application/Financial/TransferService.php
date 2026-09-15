<?php

declare(strict_types=1);

namespace App\Application\Financial;

use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\OperationRepository;
use App\Support\Identifier\PublicIdGenerator;
use App\Application\Security\PasswordTransactionAuthorizer;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class TransferService
{
    public function __construct(
        private PDO $connection,
        private AccountRepository $accountRepository,
        private OperationRepository $operationRepository,
        private LedgerEntryRepository $ledgerEntryRepository,
        private PublicIdGenerator $publicIdGenerator,
        private PasswordTransactionAuthorizer $transactionAuthorizer
    ) {
    }

    public function execute(
    int $sourceAccountId,
    int $destinationAccountId,
    string $amount,
    int $actorId,
    string $password
): array {
        if ($sourceAccountId === $destinationAccountId) {
            throw new InvalidArgumentException(
                'Source and destination accounts must be different.'
            );
        }

        $amountInCents = $this->toCents($amount);

        if ($amountInCents <= 0) {
            throw new InvalidArgumentException(
                'Transfer amount must be greater than zero.'
            );
        }

        $this->connection->beginTransaction();

        try {
            $accounts = $this->accountRepository->findTwoForUpdate(
                $sourceAccountId,
                $destinationAccountId
            );

            $sourceAccount = $accounts[$sourceAccountId];
            $destinationAccount = $accounts[$destinationAccountId];

            if ((int) $sourceAccount['user_id'] !== $actorId) {
    throw new RuntimeException(
        'User is not authorized to operate this account.'
    );
}

if (!$this->transactionAuthorizer->authorize(
    $actorId,
    $password
)) {
    throw new RuntimeException(
        'Invalid transaction authorization.'
    );
}

            if ($sourceAccount['status'] !== 'ACTIVE') {
                throw new RuntimeException(
                    'Source account is not active.'
                );
            }

            if ($destinationAccount['status'] !== 'ACTIVE') {
                throw new RuntimeException(
                    'Destination account is not active.'
                );
            }

            $sourceBalanceBefore = (string) $sourceAccount['balance'];
            $destinationBalanceBefore = (string) $destinationAccount['balance'];

            $sourceBalanceInCents = $this->toCents(
                $sourceBalanceBefore
            );

            if ($sourceBalanceInCents < $amountInCents) {
                throw new RuntimeException(
                    'Insufficient balance.'
                );
            }

            $sourceBalanceAfter = $this->fromCents(
                $sourceBalanceInCents - $amountInCents
            );

            $destinationBalanceAfter = $this->fromCents(
                $this->toCents($destinationBalanceBefore)
                + $amountInCents
            );

            $operationPublicId = $this->publicIdGenerator->generate();

            $operationId = $this->operationRepository->create(
                $operationPublicId,
                'TRANSFER',
                'COMPLETED',
                $amount,
                $sourceAccountId,
                $destinationAccountId,
                'USER',
                $actorId,
                'PASSWORD'
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
                $operationId,
                $sourceAccountId,
                'DEBIT',
                $amount,
                $sourceBalanceBefore,
                $sourceBalanceAfter
            );

            $this->ledgerEntryRepository->create(
                $this->publicIdGenerator->generate(),
                $operationId,
                $destinationAccountId,
                'CREDIT',
                $amount,
                $destinationBalanceBefore,
                $destinationBalanceAfter
            );

            $this->connection->commit();

            return [
                'operation_id' => $operationId,
                'operation_public_id' => $operationPublicId,
                'source_account_id' => $sourceAccountId,
                'destination_account_id' => $destinationAccountId,
                'amount' => $amount,
                'source_balance_before' => $sourceBalanceBefore,
                'source_balance_after' => $sourceBalanceAfter,
                'destination_balance_before' => $destinationBalanceBefore,
                'destination_balance_after' => $destinationBalanceAfter,
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
            throw new InvalidArgumentException(
                'Amount must use format 0.00.'
            );
        }

        [$integer, $decimal] = explode('.', $amount);

        return ((int) $integer * 100) + (int) $decimal;
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