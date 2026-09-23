<?php

declare(strict_types=1);

namespace App\Application\Financial;

use App\Application\Security\PasswordTransactionAuthorizer;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\OperationRepository;
use App\Infrastructure\Persistence\SplitPaymentRepository;
use App\Support\Identifier\PublicIdGenerator;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class SplitPaymentService
{
    public function __construct(
        private PDO $connection,
        private AccountRepository $accountRepository,
        private OperationRepository $operationRepository,
        private SplitPaymentRepository $splitPaymentRepository,
        private LedgerEntryRepository $ledgerEntryRepository,
        private PublicIdGenerator $publicIdGenerator,
        private PasswordTransactionAuthorizer $transactionAuthorizer
    ) {}

    public function execute(
        int $payerAccountId,
        int $supplierAccountId,
        string $grossAmount,
        string $cbsAmount,
        string $ibsAmount,
        int $actorId,
        string $password,
        string $referenceId,
        string $idempotencyKey
    ): array {
        if ($payerAccountId === $supplierAccountId) {
            throw new InvalidArgumentException(
                'Payer and supplier accounts must be different.'
            );
        }

        $grossCents = $this->toCents($grossAmount);
        $cbsCents = $this->toCents($cbsAmount);
        $ibsCents = $this->toCents($ibsAmount);

        if ($grossCents <= 0) {
            throw new InvalidArgumentException(
                'Gross amount must be greater than zero.'
            );
        }

        if (($cbsCents + $ibsCents) > $grossCents) {
            throw new InvalidArgumentException(
                'Tax amounts cannot exceed gross amount.'
            );
        }

        $supplierCents = $grossCents - $cbsCents - $ibsCents;
        $supplierAmount = $this->fromCents($supplierCents);

        $existingOperation = $this->operationRepository
            ->findByIdempotencyKey($idempotencyKey);

        if ($existingOperation !== null) {
            $existingSplit = $this->splitPaymentRepository
                ->findByOperationId((int) $existingOperation['id']);

            $sameSplit =
                $existingOperation['type'] === 'SPLIT_PAYMENT'
                && (int) $existingOperation['source_account_id'] === $payerAccountId
                && (int) $existingOperation['destination_account_id'] === $supplierAccountId
                && (string) $existingOperation['amount'] === $grossAmount
                && (int) $existingOperation['actor_id'] === $actorId
                && $existingSplit !== null
                && (string) $existingSplit['cbs_amount'] === $cbsAmount
                && (string) $existingSplit['ibs_amount'] === $ibsAmount
                && (string) $existingSplit['reference_id'] === $referenceId;

            if (!$sameSplit) {
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
                'split_payment_id' => (int) $existingSplit['id'],
                'split_payment_public_id' => $existingSplit['public_id'],
                'gross_amount' => (string) $existingSplit['gross_amount'],
                'supplier_amount' => (string) $existingSplit['supplier_amount'],
                'cbs_amount' => (string) $existingSplit['cbs_amount'],
                'ibs_amount' => (string) $existingSplit['ibs_amount'],
                'idempotent_replay' => true,
            ];
        }

        $cbsSystemAccount = $this->accountRepository
            ->findSystemAccountByRole('TAX_CBS');

        $ibsSystemAccount = $this->accountRepository
            ->findSystemAccountByRole('TAX_IBS');

        $cbsAccountId = (int) $cbsSystemAccount['id'];
        $ibsAccountId = (int) $ibsSystemAccount['id'];

        $this->connection->beginTransaction();

        try {
            $accounts = $this->accountRepository->findManyForUpdate([
                $payerAccountId,
                $supplierAccountId,
                $cbsAccountId,
                $ibsAccountId,
            ]);

            $payerAccount = $accounts[$payerAccountId];
            $supplierAccount = $accounts[$supplierAccountId];
            $cbsAccount = $accounts[$cbsAccountId];
            $ibsAccount = $accounts[$ibsAccountId];

            if (
                $payerAccount['account_type'] !== 'USER'
                || (int) $payerAccount['user_id'] !== $actorId
            ) {
                throw new RuntimeException(
                    'User is not authorized to operate this account.'
                );
            }

            if ($supplierAccount['account_type'] !== 'USER') {
                throw new RuntimeException(
                    'Supplier account must be a user account.'
                );
            }

            if (
                $cbsAccount['account_type'] !== 'SYSTEM'
                || $cbsAccount['system_role'] !== 'TAX_CBS'
            ) {
                throw new RuntimeException(
                    'Invalid CBS system account.'
                );
            }

            if (
                $ibsAccount['account_type'] !== 'SYSTEM'
                || $ibsAccount['system_role'] !== 'TAX_IBS'
            ) {
                throw new RuntimeException(
                    'Invalid IBS system account.'
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

            foreach (
                [
                    $payerAccount,
                    $supplierAccount,
                    $cbsAccount,
                    $ibsAccount,
                ] as $account
            ) {
                if ($account['status'] !== 'ACTIVE') {
                    throw new RuntimeException(
                        'Account is not active.'
                    );
                }
            }

            $payerBalanceBefore = (string) $payerAccount['balance'];
            $supplierBalanceBefore = (string) $supplierAccount['balance'];
            $cbsBalanceBefore = (string) $cbsAccount['balance'];
            $ibsBalanceBefore = (string) $ibsAccount['balance'];

            $payerBalanceCents = $this->toCents($payerBalanceBefore);

            if ($payerBalanceCents < $grossCents) {
                throw new RuntimeException(
                    'Insufficient balance.'
                );
            }

            $payerBalanceAfter = $this->fromCents(
                $payerBalanceCents - $grossCents
            );

            $supplierBalanceAfter = $this->fromCents(
                $this->toCents($supplierBalanceBefore) + $supplierCents
            );

            $cbsBalanceAfter = $this->fromCents(
                $this->toCents($cbsBalanceBefore) + $cbsCents
            );

            $ibsBalanceAfter = $this->fromCents(
                $this->toCents($ibsBalanceBefore) + $ibsCents
            );

            $operationPublicId = $this->publicIdGenerator->generate();

            $operationId = $this->operationRepository->create(
                $operationPublicId,
                'SPLIT_PAYMENT',
                'COMPLETED',
                $grossAmount,
                $payerAccountId,
                $supplierAccountId,
                'USER',
                $actorId,
                'PASSWORD',
                $referenceId,
                $idempotencyKey
            );

            $splitPaymentPublicId = $this->publicIdGenerator->generate();

            $splitPaymentId = $this->splitPaymentRepository->create(
                $splitPaymentPublicId,
                $operationId,
                $grossAmount,
                $supplierAmount,
                $cbsAmount,
                $ibsAmount,
                'COMPLETED',
                $referenceId
            );

            $this->accountRepository->updateBalance(
                $payerAccountId,
                $payerBalanceAfter
            );

            $this->accountRepository->updateBalance(
                $supplierAccountId,
                $supplierBalanceAfter
            );

            $this->accountRepository->updateBalance(
                $cbsAccountId,
                $cbsBalanceAfter
            );

            $this->accountRepository->updateBalance(
                $ibsAccountId,
                $ibsBalanceAfter
            );

            $this->ledgerEntryRepository->create(
                $this->publicIdGenerator->generate(),
                $operationId,
                $payerAccountId,
                'DEBIT',
                $grossAmount,
                $payerBalanceBefore,
                $payerBalanceAfter
            );

            if ($supplierCents > 0) {
                $this->ledgerEntryRepository->create(
                    $this->publicIdGenerator->generate(),
                    $operationId,
                    $supplierAccountId,
                    'CREDIT',
                    $supplierAmount,
                    $supplierBalanceBefore,
                    $supplierBalanceAfter
                );
            }

            if ($cbsCents > 0) {
                $this->ledgerEntryRepository->create(
                    $this->publicIdGenerator->generate(),
                    $operationId,
                    $cbsAccountId,
                    'CREDIT',
                    $cbsAmount,
                    $cbsBalanceBefore,
                    $cbsBalanceAfter
                );
            }

            if ($ibsCents > 0) {
                $this->ledgerEntryRepository->create(
                    $this->publicIdGenerator->generate(),
                    $operationId,
                    $ibsAccountId,
                    'CREDIT',
                    $ibsAmount,
                    $ibsBalanceBefore,
                    $ibsBalanceAfter
                );
            }

            $this->connection->commit();

            return [
                'operation_id' => $operationId,
                'operation_public_id' => $operationPublicId,
                'split_payment_id' => $splitPaymentId,
                'split_payment_public_id' => $splitPaymentPublicId,
                'gross_amount' => $grossAmount,
                'supplier_amount' => $supplierAmount,
                'cbs_amount' => $cbsAmount,
                'ibs_amount' => $ibsAmount,
                'payer_balance_before' => $payerBalanceBefore,
                'payer_balance_after' => $payerBalanceAfter,
                'supplier_balance_before' => $supplierBalanceBefore,
                'supplier_balance_after' => $supplierBalanceAfter,
                'cbs_balance_before' => $cbsBalanceBefore,
                'cbs_balance_after' => $cbsBalanceAfter,
                'ibs_balance_before' => $ibsBalanceBefore,
                'ibs_balance_after' => $ibsBalanceAfter,
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
            throw new InvalidArgumentException(
                'Invalid monetary value.'
            );
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
