<?php

declare(strict_types=1);

namespace Tests\Integration\Application\Financial;

use App\Application\Financial\DepositService;
use App\Application\Financial\ReversalService;
use App\Application\Financial\TransferService;
use App\Application\Security\PasswordTransactionAuthorizer;
use App\Domain\Account\AccountNumberGenerator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\OperationRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReversalServiceTest extends TestCase
{
    private PDO $connection;
    private int $sourceUserId;
    private int $destinationUserId;
    private int $sourceAccountId;
    private int $destinationAccountId;

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
                    'reversal.source@integration.wallet.local',
                    'reversal.destination@integration.wallet.local'
                )
            )
            "
        );

        $this->connection->exec(
            "
            DELETE FROM operations
            WHERE reversal_of_operation_id IN (
                SELECT id
                FROM (
                    SELECT o.id
                    FROM operations o
                    WHERE o.source_account_id IN (
                        SELECT a.id
                        FROM accounts a
                        INNER JOIN users u
                            ON u.id = a.user_id
                        WHERE u.email IN (
                            'reversal.source@integration.wallet.local',
                            'reversal.destination@integration.wallet.local'
                        )
                    )
                    OR o.destination_account_id IN (
                        SELECT a.id
                        FROM accounts a
                        INNER JOIN users u
                            ON u.id = a.user_id
                        WHERE u.email IN (
                            'reversal.source@integration.wallet.local',
                            'reversal.destination@integration.wallet.local'
                        )
                    )
                ) original_operations
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
                    'reversal.source@integration.wallet.local',
                    'reversal.destination@integration.wallet.local'
                )
            )
            OR destination_account_id IN (
                SELECT a.id
                FROM accounts a
                INNER JOIN users u
                    ON u.id = a.user_id
                WHERE u.email IN (
                    'reversal.source@integration.wallet.local',
                    'reversal.destination@integration.wallet.local'
                )
            )
            "
        );

        $this->connection->exec(
            "
            DELETE FROM accounts
            WHERE user_id IN (
                SELECT id
                FROM users
                WHERE email IN (
                    'reversal.source@integration.wallet.local',
                    'reversal.destination@integration.wallet.local'
                )
            )
            "
        );

        $this->connection->exec(
            "
            DELETE FROM users
            WHERE email IN (
                'reversal.source@integration.wallet.local',
                'reversal.destination@integration.wallet.local'
            )
            "
        );

        $publicIdGenerator = new PublicIdGenerator();
        $userRepository = new UserRepository($this->connection);
        $accountRepository = new AccountRepository($this->connection);
        $accountNumberGenerator = new AccountNumberGenerator();

        $this->sourceUserId = $userRepository->create(
            $publicIdGenerator->generate(),
            'Reversal Source User',
            'reversal.source@integration.wallet.local',
            password_hash('SenhaTeste123!', PASSWORD_DEFAULT)
        );

        $this->destinationUserId = $userRepository->create(
            $publicIdGenerator->generate(),
            'Reversal Destination User',
            'reversal.destination@integration.wallet.local',
            password_hash('SenhaTeste123!', PASSWORD_DEFAULT)
        );

        $sourceAccount = $accountNumberGenerator->generate();
        $destinationAccount = $accountNumberGenerator->generate();

        $this->sourceAccountId = $accountRepository->create(
            $publicIdGenerator->generate(),
            $this->sourceUserId,
            '0001',
            $sourceAccount['number'],
            $sourceAccount['digit']
        );

        $this->destinationAccountId = $accountRepository->create(
            $publicIdGenerator->generate(),
            $this->destinationUserId,
            '0001',
            $destinationAccount['number'],
            $destinationAccount['digit']
        );

        $depositService = new DepositService(
            $this->connection,
            $accountRepository,
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            $publicIdGenerator
        );

        $depositService->execute(
            $this->sourceAccountId,
            '100.00',
            'INTEGRATION-REVERSAL-FUND-001',
            $this->sourceUserId
        );
    }

    public function testCompletedTransferCanBeReversed(): void
    {
        $userRepository = new UserRepository($this->connection);
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $authorizer = new PasswordTransactionAuthorizer(
            $userRepository
        );

        $transferService = new TransferService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        $transfer = $transferService->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
            $this->sourceUserId,
            'SenhaTeste123!',
            'INTEGRATION-REVERSAL-TRANSFER-001'
        );

        $reversalService = new ReversalService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        $reversal = $reversalService->execute(
            $transfer['operation_id'],
            $this->sourceUserId,
            'SenhaTeste123!',
            'INTEGRATION-REVERSAL-001'
        );

        self::assertSame(
            $transfer['operation_id'],
            $reversal['reversal_of_operation_id']
        );

        self::assertSame('25.50', $reversal['amount']);
        self::assertSame('74.50', $reversal['source_balance_before']);
        self::assertSame('100.00', $reversal['source_balance_after']);
        self::assertSame('25.50', $reversal['destination_balance_before']);
        self::assertSame('0.00', $reversal['destination_balance_after']);
        self::assertFalse($reversal['idempotent_replay']);

        $balanceStatement = $this->connection->prepare(
            '
            SELECT id, balance
            FROM accounts
            WHERE id IN (:source_id, :destination_id)
            '
        );

        $balanceStatement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($balanceStatement->fetchAll() as $account) {
            $accounts[(int) $account['id']] = (string) $account['balance'];
        }

        self::assertSame(
            '100.00',
            $accounts[$this->sourceAccountId]
        );

        self::assertSame(
            '0.00',
            $accounts[$this->destinationAccountId]
        );

        $operationStatement = $this->connection->prepare(
            '
            SELECT
                type,
                status,
                amount,
                source_account_id,
                destination_account_id,
                reversal_of_operation_id
            FROM operations
            WHERE id = :id
            '
        );

        $operationStatement->execute([
            'id' => $reversal['operation_id'],
        ]);

        $operation = $operationStatement->fetch();

        self::assertNotFalse($operation);
        self::assertSame('REVERSAL', $operation['type']);
        self::assertSame('COMPLETED', $operation['status']);
        self::assertSame('25.50', (string) $operation['amount']);
        self::assertSame(
            $this->destinationAccountId,
            (int) $operation['source_account_id']
        );
        self::assertSame(
            $this->sourceAccountId,
            (int) $operation['destination_account_id']
        );
        self::assertSame(
            $transfer['operation_id'],
            (int) $operation['reversal_of_operation_id']
        );

        $ledgerStatement = $this->connection->prepare(
            '
            SELECT
                account_id,
                entry_type,
                amount,
                balance_before,
                balance_after
            FROM ledger_entries
            WHERE operation_id = :operation_id
            ORDER BY id
            '
        );

        $ledgerStatement->execute([
            'operation_id' => $reversal['operation_id'],
        ]);

        $entries = $ledgerStatement->fetchAll();

        self::assertCount(2, $entries);

        self::assertSame(
            $this->sourceAccountId,
            (int) $entries[0]['account_id']
        );
        self::assertSame('CREDIT', $entries[0]['entry_type']);
        self::assertSame('25.50', (string) $entries[0]['amount']);
        self::assertSame('74.50', (string) $entries[0]['balance_before']);
        self::assertSame('100.00', (string) $entries[0]['balance_after']);

        self::assertSame(
            $this->destinationAccountId,
            (int) $entries[1]['account_id']
        );
        self::assertSame('DEBIT', $entries[1]['entry_type']);
        self::assertSame('25.50', (string) $entries[1]['amount']);
        self::assertSame('25.50', (string) $entries[1]['balance_before']);
        self::assertSame('0.00', (string) $entries[1]['balance_after']);
    }

    public function testTransferCannotBeReversedTwice(): void
    {
        $userRepository = new UserRepository($this->connection);
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $authorizer = new PasswordTransactionAuthorizer(
            $userRepository
        );

        $transferService = new TransferService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        $transfer = $transferService->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
            $this->sourceUserId,
            'SenhaTeste123!',
            'INTEGRATION-REVERSAL-TWICE-TRANSFER'
        );

        $reversalService = new ReversalService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        $reversalService->execute(
            $transfer['operation_id'],
            $this->sourceUserId,
            'SenhaTeste123!',
            'INTEGRATION-REVERSAL-TWICE-001'
        );

        try {
            $reversalService->execute(
                $transfer['operation_id'],
                $this->sourceUserId,
                'SenhaTeste123!',
                'INTEGRATION-REVERSAL-TWICE-002'
            );

            self::fail('Expected duplicate reversal to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Operation already reversed.',
                $exception->getMessage()
            );
        }

        $statement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM operations
        WHERE reversal_of_operation_id = :operation_id
        '
        );

        $statement->execute([
            'operation_id' => $transfer['operation_id'],
        ]);

        self::assertSame(
            1,
            (int) $statement->fetchColumn()
        );
    }

    public function testRepeatedReversalWithSameIdempotencyKeyDoesNotReverseTwice(): void
    {
        $userRepository = new UserRepository($this->connection);
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $authorizer = new PasswordTransactionAuthorizer(
            $userRepository
        );

        $transferService = new TransferService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        $transfer = $transferService->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
            $this->sourceUserId,
            'SenhaTeste123!',
            'INTEGRATION-REVERSAL-IDEMPOTENT-TRANSFER'
        );

        $reversalService = new ReversalService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        $idempotencyKey = 'INTEGRATION-REVERSAL-IDEMPOTENT-001';

        $firstResult = $reversalService->execute(
            $transfer['operation_id'],
            $this->sourceUserId,
            'SenhaTeste123!',
            $idempotencyKey
        );

        $secondResult = $reversalService->execute(
            $transfer['operation_id'],
            $this->sourceUserId,
            'SenhaTeste123!',
            $idempotencyKey
        );

        self::assertFalse($firstResult['idempotent_replay']);
        self::assertTrue($secondResult['idempotent_replay']);

        self::assertSame(
            $firstResult['operation_id'],
            $secondResult['operation_id']
        );

        $statement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM operations
        WHERE reversal_of_operation_id = :operation_id
        '
        );

        $statement->execute([
            'operation_id' => $transfer['operation_id'],
        ]);

        self::assertSame(
            1,
            (int) $statement->fetchColumn()
        );

        $balanceStatement = $this->connection->prepare(
            '
        SELECT id, balance
        FROM accounts
        WHERE id IN (:source_id, :destination_id)
        '
        );

        $balanceStatement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($balanceStatement->fetchAll() as $account) {
            $accounts[(int) $account['id']] = (string) $account['balance'];
        }

        self::assertSame(
            '100.00',
            $accounts[$this->sourceAccountId]
        );

        self::assertSame(
            '0.00',
            $accounts[$this->destinationAccountId]
        );
    }

    public function testReversalFailsWhenDestinationAccountHasInsufficientBalance(): void
    {
        $userRepository = new UserRepository($this->connection);
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $authorizer = new PasswordTransactionAuthorizer(
            $userRepository
        );

        $transferService = new TransferService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        $originalTransfer = $transferService->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '80.00',
            $this->sourceUserId,
            'SenhaTeste123!',
            'INTEGRATION-REVERSAL-INSUFFICIENT-ORIGINAL'
        );

        $transferService->execute(
            $this->destinationAccountId,
            $this->sourceAccountId,
            '70.00',
            $this->destinationUserId,
            'SenhaTeste123!',
            'INTEGRATION-REVERSAL-INSUFFICIENT-SPEND'
        );

        $reversalService = new ReversalService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        try {
            $reversalService->execute(
                $originalTransfer['operation_id'],
                $this->sourceUserId,
                'SenhaTeste123!',
                'INTEGRATION-REVERSAL-INSUFFICIENT-001'
            );

            self::fail(
                'Expected reversal to fail due to insufficient destination balance.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Destination account has insufficient balance for reversal.',
                $exception->getMessage()
            );
        }

        $balanceStatement = $this->connection->prepare(
            '
        SELECT id, balance
        FROM accounts
        WHERE id IN (:source_id, :destination_id)
        '
        );

        $balanceStatement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($balanceStatement->fetchAll() as $account) {
            $accounts[(int) $account['id']] = (string) $account['balance'];
        }

        self::assertSame(
            '90.00',
            $accounts[$this->sourceAccountId]
        );

        self::assertSame(
            '10.00',
            $accounts[$this->destinationAccountId]
        );

        $operationStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM operations
        WHERE reversal_of_operation_id = :operation_id
        '
        );

        $operationStatement->execute([
            'operation_id' => $originalTransfer['operation_id'],
        ]);

        self::assertSame(
            0,
            (int) $operationStatement->fetchColumn()
        );
    }

    public function testUserCannotReverseTransferTheyDoNotOwn(): void
    {
        $userRepository = new UserRepository($this->connection);
        $accountRepository = new AccountRepository($this->connection);
        $operationRepository = new OperationRepository($this->connection);
        $ledgerEntryRepository = new LedgerEntryRepository($this->connection);
        $publicIdGenerator = new PublicIdGenerator();

        $authorizer = new PasswordTransactionAuthorizer(
            $userRepository
        );

        $transferService = new TransferService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        $transfer = $transferService->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
            $this->sourceUserId,
            'SenhaTeste123!',
            'INTEGRATION-REVERSAL-UNAUTHORIZED-TRANSFER'
        );

        $reversalService = new ReversalService(
            $this->connection,
            $accountRepository,
            $operationRepository,
            $ledgerEntryRepository,
            $publicIdGenerator,
            $authorizer
        );

        try {
            $reversalService->execute(
                $transfer['operation_id'],
                $this->destinationUserId,
                'SenhaTeste123!',
                'INTEGRATION-REVERSAL-UNAUTHORIZED-001'
            );

            self::fail(
                'Expected unauthorized reversal to fail.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'User is not authorized to reverse this operation.',
                $exception->getMessage()
            );
        }

        $balanceStatement = $this->connection->prepare(
            '
        SELECT id, balance
        FROM accounts
        WHERE id IN (:source_id, :destination_id)
        '
        );

        $balanceStatement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($balanceStatement->fetchAll() as $account) {
            $accounts[(int) $account['id']] = (string) $account['balance'];
        }

        self::assertSame(
            '74.50',
            $accounts[$this->sourceAccountId]
        );

        self::assertSame(
            '25.50',
            $accounts[$this->destinationAccountId]
        );

        $operationStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM operations
        WHERE reversal_of_operation_id = :operation_id
        '
        );

        $operationStatement->execute([
            'operation_id' => $transfer['operation_id'],
        ]);

        self::assertSame(
            0,
            (int) $operationStatement->fetchColumn()
        );
    }
}
