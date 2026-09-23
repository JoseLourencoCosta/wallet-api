<?php

declare(strict_types=1);

namespace Tests\Integration\Application\Financial;

use App\Application\Financial\DepositService;
use App\Application\Financial\SplitPaymentService;
use App\Application\Security\PasswordTransactionAuthorizer;
use App\Domain\Account\AccountNumberGenerator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\OperationRepository;
use App\Infrastructure\Persistence\SplitPaymentRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

final class SplitPaymentServiceTest extends TestCase
{
    private PDO $connection;
    private int $payerUserId;
    private int $supplierUserId;
    private int $payerAccountId;
    private int $supplierAccountId;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create();

        $this->connection->exec(
            "
            DELETE FROM ledger_entries
            WHERE account_id IN (
                SELECT a.id
                FROM accounts a
                INNER JOIN users u
                    ON u.id = a.user_id
                WHERE u.email IN (
                    'split.payer@integration.wallet.local',
                    'split.supplier@integration.wallet.local'
                )
            )
            OR account_id IN (
                SELECT id
                FROM accounts
                WHERE system_role IN ('TAX_CBS', 'TAX_IBS')
            )
            "
        );

        $this->connection->exec(
            "
            DELETE FROM split_payments
            WHERE operation_id IN (
                SELECT id
                FROM operations
                WHERE source_account_id IN (
                    SELECT a.id
                    FROM accounts a
                    INNER JOIN users u
                        ON u.id = a.user_id
                    WHERE u.email IN (
                        'split.payer@integration.wallet.local',
                        'split.supplier@integration.wallet.local'
                    )
                )
                OR destination_account_id IN (
                    SELECT a.id
                    FROM accounts a
                    INNER JOIN users u
                        ON u.id = a.user_id
                    WHERE u.email IN (
                        'split.payer@integration.wallet.local',
                        'split.supplier@integration.wallet.local'
                    )
                )
            )
            "
        );

        $this->connection->exec(
            "
            DELETE FROM operations
            WHERE source_account_id IN (
                SELECT a.id
                FROM accounts a
                INNER JOIN users u
                    ON u.id = a.user_id
                WHERE u.email IN (
                    'split.payer@integration.wallet.local',
                    'split.supplier@integration.wallet.local'
                )
            )
            OR destination_account_id IN (
                SELECT a.id
                FROM accounts a
                INNER JOIN users u
                    ON u.id = a.user_id
                WHERE u.email IN (
                    'split.payer@integration.wallet.local',
                    'split.supplier@integration.wallet.local'
                )
            )
            "
        );

        $this->connection->exec(
            "
            UPDATE accounts
            SET balance = 0.00
            WHERE system_role IN ('TAX_CBS', 'TAX_IBS')
            "
        );

        $this->connection->exec(
            "
            DELETE FROM accounts
            WHERE user_id IN (
                SELECT id
                FROM users
                WHERE email IN (
                    'split.payer@integration.wallet.local',
                    'split.supplier@integration.wallet.local'
                )
            )
            "
        );

        $this->connection->exec(
            "
            DELETE FROM users
            WHERE email IN (
                'split.payer@integration.wallet.local',
                'split.supplier@integration.wallet.local'
            )
            "
        );

        $publicIdGenerator = new PublicIdGenerator();
        $userRepository = new UserRepository($this->connection);
        $accountRepository = new AccountRepository($this->connection);
        $accountNumberGenerator = new AccountNumberGenerator();

        $this->payerUserId = $userRepository->create(
            $publicIdGenerator->generate(),
            'Split Payer User',
            'split.payer@integration.wallet.local',
            password_hash('SenhaTeste123!', PASSWORD_DEFAULT)
        );

        $this->supplierUserId = $userRepository->create(
            $publicIdGenerator->generate(),
            'Split Supplier User',
            'split.supplier@integration.wallet.local',
            password_hash('SenhaTeste123!', PASSWORD_DEFAULT)
        );

        $payerAccount = $accountNumberGenerator->generate();
        $supplierAccount = $accountNumberGenerator->generate();

        $this->payerAccountId = $accountRepository->create(
            $publicIdGenerator->generate(),
            $this->payerUserId,
            '0001',
            $payerAccount['number'],
            $payerAccount['digit']
        );

        $this->supplierAccountId = $accountRepository->create(
            $publicIdGenerator->generate(),
            $this->supplierUserId,
            '0001',
            $supplierAccount['number'],
            $supplierAccount['digit']
        );

        $depositService = new DepositService(
            $this->connection,
            $accountRepository,
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            $publicIdGenerator
        );

        $depositService->execute(
            $this->payerAccountId,
            '100.00',
            'INTEGRATION-SPLIT-FUND-001',
            $this->payerUserId
        );
    }

    public function testSplitPaymentSegregatesSupplierCbsAndIbsAmounts(): void
    {
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $splitPaymentRepository = new SplitPaymentRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $service = new SplitPaymentService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $splitPaymentRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        $result = $service->execute(
            $this->payerAccountId,
            $this->supplierAccountId,
            '100.00',
            '10.00',
            '8.00',
            $this->payerUserId,
            'SenhaTeste123!',
            'INTEGRATION-SPLIT-REFERENCE-001',
            'INTEGRATION-SPLIT-IDEMPOTENCY-001'
        );

        self::assertSame('100.00', $result['gross_amount']);
        self::assertSame('82.00', $result['supplier_amount']);
        self::assertSame('10.00', $result['cbs_amount']);
        self::assertSame('8.00', $result['ibs_amount']);

        self::assertSame('100.00', $result['payer_balance_before']);
        self::assertSame('0.00', $result['payer_balance_after']);

        self::assertSame('0.00', $result['supplier_balance_before']);
        self::assertSame('82.00', $result['supplier_balance_after']);

        self::assertSame('0.00', $result['cbs_balance_before']);
        self::assertSame('10.00', $result['cbs_balance_after']);

        self::assertSame('0.00', $result['ibs_balance_before']);
        self::assertSame('8.00', $result['ibs_balance_after']);

        self::assertFalse($result['idempotent_replay']);

        $operationStatement = $this->connection->prepare(
            '
            SELECT
                type,
                status,
                amount,
                source_account_id,
                destination_account_id,
                reference_id
            FROM operations
            WHERE id = :id
            '
        );

        $operationStatement->execute([
            'id' => $result['operation_id'],
        ]);

        $operation = $operationStatement->fetch();

        self::assertNotFalse($operation);
        self::assertSame('SPLIT_PAYMENT', $operation['type']);
        self::assertSame('COMPLETED', $operation['status']);
        self::assertSame('100.00', (string) $operation['amount']);
        self::assertSame(
            $this->payerAccountId,
            (int) $operation['source_account_id']
        );
        self::assertSame(
            $this->supplierAccountId,
            (int) $operation['destination_account_id']
        );
        self::assertSame(
            'INTEGRATION-SPLIT-REFERENCE-001',
            $operation['reference_id']
        );

        $splitStatement = $this->connection->prepare(
            '
            SELECT
                gross_amount,
                supplier_amount,
                cbs_amount,
                ibs_amount,
                status,
                reference_id
            FROM split_payments
            WHERE id = :id
            '
        );

        $splitStatement->execute([
            'id' => $result['split_payment_id'],
        ]);

        $split = $splitStatement->fetch();

        self::assertNotFalse($split);
        self::assertSame('100.00', (string) $split['gross_amount']);
        self::assertSame('82.00', (string) $split['supplier_amount']);
        self::assertSame('10.00', (string) $split['cbs_amount']);
        self::assertSame('8.00', (string) $split['ibs_amount']);
        self::assertSame('COMPLETED', $split['status']);
        self::assertSame(
            'INTEGRATION-SPLIT-REFERENCE-001',
            $split['reference_id']
        );

        $ledgerStatement = $this->connection->prepare(
            '
            SELECT
                a.account_type,
                a.system_role,
                le.entry_type,
                le.amount
            FROM ledger_entries le
            INNER JOIN accounts a
                ON a.id = le.account_id
            WHERE le.operation_id = :operation_id
            ORDER BY le.id
            '
        );

        $ledgerStatement->execute([
            'operation_id' => $result['operation_id'],
        ]);

        $entries = $ledgerStatement->fetchAll();

        self::assertCount(4, $entries);

        self::assertSame('DEBIT', $entries[0]['entry_type']);
        self::assertSame('100.00', (string) $entries[0]['amount']);

        self::assertSame('CREDIT', $entries[1]['entry_type']);
        self::assertSame('82.00', (string) $entries[1]['amount']);

        self::assertSame('SYSTEM', $entries[2]['account_type']);
        self::assertSame('TAX_CBS', $entries[2]['system_role']);
        self::assertSame('CREDIT', $entries[2]['entry_type']);
        self::assertSame('10.00', (string) $entries[2]['amount']);

        self::assertSame('SYSTEM', $entries[3]['account_type']);
        self::assertSame('TAX_IBS', $entries[3]['system_role']);
        self::assertSame('CREDIT', $entries[3]['entry_type']);
        self::assertSame('8.00', (string) $entries[3]['amount']);

        $debits = 0;
        $credits = 0;

        foreach ($entries as $entry) {
            $amountCents = (int) str_replace(
                '.',
                '',
                (string) $entry['amount']
            );

            if ($entry['entry_type'] === 'DEBIT') {
                $debits += $amountCents;
            }

            if ($entry['entry_type'] === 'CREDIT') {
                $credits += $amountCents;
            }
        }

        self::assertSame(10000, $debits);
        self::assertSame(10000, $credits);
    }

    public function testRepeatedSplitPaymentWithSameIdempotencyKeyDoesNotMoveMoneyTwice(): void
    {
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $splitPaymentRepository = new SplitPaymentRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $service = new SplitPaymentService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $splitPaymentRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        $idempotencyKey = 'INTEGRATION-SPLIT-IDEMPOTENT-001';

        $firstResult = $service->execute(
            $this->payerAccountId,
            $this->supplierAccountId,
            '100.00',
            '10.00',
            '8.00',
            $this->payerUserId,
            'SenhaTeste123!',
            'INTEGRATION-SPLIT-IDEMPOTENT-REFERENCE',
            $idempotencyKey
        );

        $secondResult = $service->execute(
            $this->payerAccountId,
            $this->supplierAccountId,
            '100.00',
            '10.00',
            '8.00',
            $this->payerUserId,
            'SenhaTeste123!',
            'INTEGRATION-SPLIT-IDEMPOTENT-REFERENCE',
            $idempotencyKey
        );

        self::assertFalse($firstResult['idempotent_replay']);
        self::assertTrue($secondResult['idempotent_replay']);

        self::assertSame(
            $firstResult['operation_id'],
            $secondResult['operation_id']
        );

        self::assertSame(
            $firstResult['split_payment_id'],
            $secondResult['split_payment_id']
        );

        $operationStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM operations
        WHERE idempotency_key = :idempotency_key
        '
        );

        $operationStatement->execute([
            'idempotency_key' => $idempotencyKey,
        ]);

        self::assertSame(
            1,
            (int) $operationStatement->fetchColumn()
        );

        $ledgerStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM ledger_entries
        WHERE operation_id = :operation_id
        '
        );

        $ledgerStatement->execute([
            'operation_id' => $firstResult['operation_id'],
        ]);

        self::assertSame(
            4,
            (int) $ledgerStatement->fetchColumn()
        );

        $balanceStatement = $this->connection->prepare(
            '
        SELECT
            id,
            balance,
            system_role
        FROM accounts
        WHERE id IN (:payer_id, :supplier_id)
           OR system_role IN (:cbs_role, :ibs_role)
        '
        );

        $balanceStatement->execute([
            'payer_id' => $this->payerAccountId,
            'supplier_id' => $this->supplierAccountId,
            'cbs_role' => 'TAX_CBS',
            'ibs_role' => 'TAX_IBS',
        ]);

        $payerBalance = null;
        $supplierBalance = null;
        $cbsBalance = null;
        $ibsBalance = null;

        foreach ($balanceStatement->fetchAll() as $account) {
            $accountId = (int) $account['id'];

            if ($accountId === $this->payerAccountId) {
                $payerBalance = (string) $account['balance'];
            }

            if ($accountId === $this->supplierAccountId) {
                $supplierBalance = (string) $account['balance'];
            }

            if ($account['system_role'] === 'TAX_CBS') {
                $cbsBalance = (string) $account['balance'];
            }

            if ($account['system_role'] === 'TAX_IBS') {
                $ibsBalance = (string) $account['balance'];
            }
        }

        self::assertSame('0.00', $payerBalance);
        self::assertSame('82.00', $supplierBalance);
        self::assertSame('10.00', $cbsBalance);
        self::assertSame('8.00', $ibsBalance);
    }

    public function testSameIdempotencyKeyCannotBeReusedForDifferentSplitPayment(): void
    {
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $splitPaymentRepository = new SplitPaymentRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $service = new SplitPaymentService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $splitPaymentRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        $idempotencyKey = 'INTEGRATION-SPLIT-CONFLICT-001';

        $service->execute(
            $this->payerAccountId,
            $this->supplierAccountId,
            '100.00',
            '10.00',
            '8.00',
            $this->payerUserId,
            'SenhaTeste123!',
            'INTEGRATION-SPLIT-CONFLICT-REFERENCE',
            $idempotencyKey
        );

        try {
            $service->execute(
                $this->payerAccountId,
                $this->supplierAccountId,
                '100.00',
                '12.00',
                '8.00',
                $this->payerUserId,
                'SenhaTeste123!',
                'INTEGRATION-SPLIT-CONFLICT-REFERENCE',
                $idempotencyKey
            );

            self::fail(
                'Expected idempotency conflict.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Idempotency key already used for another operation.',
                $exception->getMessage()
            );
        }

        $operationStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM operations
        WHERE idempotency_key = :idempotency_key
        '
        );

        $operationStatement->execute([
            'idempotency_key' => $idempotencyKey,
        ]);

        self::assertSame(
            1,
            (int) $operationStatement->fetchColumn()
        );

        $splitStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM split_payments
        WHERE operation_id IN (
            SELECT id
            FROM operations
            WHERE idempotency_key = :idempotency_key
        )
        '
        );

        $splitStatement->execute([
            'idempotency_key' => $idempotencyKey,
        ]);

        self::assertSame(
            1,
            (int) $splitStatement->fetchColumn()
        );

        $balanceStatement = $this->connection->prepare(
            '
        SELECT
            id,
            balance,
            system_role
        FROM accounts
        WHERE id IN (:payer_id, :supplier_id)
           OR system_role IN (:cbs_role, :ibs_role)
        '
        );

        $balanceStatement->execute([
            'payer_id' => $this->payerAccountId,
            'supplier_id' => $this->supplierAccountId,
            'cbs_role' => 'TAX_CBS',
            'ibs_role' => 'TAX_IBS',
        ]);

        $payerBalance = null;
        $supplierBalance = null;
        $cbsBalance = null;
        $ibsBalance = null;

        foreach ($balanceStatement->fetchAll() as $account) {
            $accountId = (int) $account['id'];

            if ($accountId === $this->payerAccountId) {
                $payerBalance = (string) $account['balance'];
            }

            if ($accountId === $this->supplierAccountId) {
                $supplierBalance = (string) $account['balance'];
            }

            if ($account['system_role'] === 'TAX_CBS') {
                $cbsBalance = (string) $account['balance'];
            }

            if ($account['system_role'] === 'TAX_IBS') {
                $ibsBalance = (string) $account['balance'];
            }
        }

        self::assertSame('0.00', $payerBalance);
        self::assertSame('82.00', $supplierBalance);
        self::assertSame('10.00', $cbsBalance);
        self::assertSame('8.00', $ibsBalance);
    }

    public function testSplitPaymentWithInsufficientBalanceDoesNotChangeFinancialState(): void
    {
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $splitPaymentRepository = new SplitPaymentRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $service = new SplitPaymentService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $splitPaymentRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        try {
            $service->execute(
                $this->payerAccountId,
                $this->supplierAccountId,
                '150.00',
                '15.00',
                '12.00',
                $this->payerUserId,
                'SenhaTeste123!',
                'INTEGRATION-SPLIT-INSUFFICIENT-REFERENCE',
                'INTEGRATION-SPLIT-INSUFFICIENT-001'
            );

            self::fail(
                'Expected insufficient balance to fail.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Insufficient balance.',
                $exception->getMessage()
            );
        }

        $balanceStatement = $this->connection->prepare(
            '
        SELECT
            id,
            balance,
            system_role
        FROM accounts
        WHERE id IN (:payer_id, :supplier_id)
           OR system_role IN (:cbs_role, :ibs_role)
        '
        );

        $balanceStatement->execute([
            'payer_id' => $this->payerAccountId,
            'supplier_id' => $this->supplierAccountId,
            'cbs_role' => 'TAX_CBS',
            'ibs_role' => 'TAX_IBS',
        ]);

        $payerBalance = null;
        $supplierBalance = null;
        $cbsBalance = null;
        $ibsBalance = null;

        foreach ($balanceStatement->fetchAll() as $account) {
            $accountId = (int) $account['id'];

            if ($accountId === $this->payerAccountId) {
                $payerBalance = (string) $account['balance'];
            }

            if ($accountId === $this->supplierAccountId) {
                $supplierBalance = (string) $account['balance'];
            }

            if ($account['system_role'] === 'TAX_CBS') {
                $cbsBalance = (string) $account['balance'];
            }

            if ($account['system_role'] === 'TAX_IBS') {
                $ibsBalance = (string) $account['balance'];
            }
        }

        self::assertSame('100.00', $payerBalance);
        self::assertSame('0.00', $supplierBalance);
        self::assertSame('0.00', $cbsBalance);
        self::assertSame('0.00', $ibsBalance);

        $operationStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM operations
        WHERE idempotency_key = :idempotency_key
        '
        );

        $operationStatement->execute([
            'idempotency_key' => 'INTEGRATION-SPLIT-INSUFFICIENT-001',
        ]);

        self::assertSame(
            0,
            (int) $operationStatement->fetchColumn()
        );

        $splitStatement = $this->connection->query(
            'SELECT COUNT(*) FROM split_payments'
        );

        self::assertSame(
            0,
            (int) $splitStatement->fetchColumn()
        );
    }
}
