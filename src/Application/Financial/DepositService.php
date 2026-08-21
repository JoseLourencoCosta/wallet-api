<?php

declare(strict_types=1);

namespace App\Application\Financial;

use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\OperationRepository;
use App\Support\Identifier\PublicIdGenerator;
use InvalidArgumentException;
use PDO;
use Throwable;

final class DepositService
{
    public function __construct(
        private PDO $connection,
        private AccountRepository $accountRepository,
        private OperationRepository $operationRepository,
        private LedgerEntryRepository $ledgerEntryRepository,
        private PublicIdGenerator $publicIdGenerator
    ) {
    }

    public function execute(
        int $accountId,
        string $amount,
        string $referenceId,
        int $actorId
    ): array {
        $amountInCents = $this->toCents($amount);

        if ($amountInCents <= 0) {
            throw new InvalidArgumentException(
                'Deposit amount must be greater than zero.'
            );
        }

        $this->connection->beginTransaction();

        try {
            $balanceBefore = $this->accountRepository->findBalanceForUpdate(
            $accountId
            );

            $balanceAfter = $this->fromCents(
                $this->toCents($balanceBefore) + $amountInCents
            );

            $operationPublicId = $this->publicIdGenerator->generate();

            $operationId = $this->operationRepository->create(
                $operationPublicId,
                'DEPOSIT',
                'COMPLETED',
                $amount,
                null,
                $accountId,
                'USER',
                $actorId,
                'PASSWORD',
                $referenceId
            );

            $this->accountRepository->updateBalance(
                $accountId,
                $balanceAfter
            );

            $ledgerPublicId = $this->publicIdGenerator->generate();

            $this->ledgerEntryRepository->create(
                $ledgerPublicId,
                $operationId,
                $accountId,
                'CREDIT',
                $amount,
                $balanceBefore,
                $balanceAfter
            );

            $this->connection->commit();

            return [
                'operation_id' => $operationId,
                'operation_public_id' => $operationPublicId,
                'account_id' => $accountId,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
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