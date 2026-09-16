<?php

declare(strict_types=1);

namespace Tests\Integration\Application\Financial;

use App\Application\Financial\DepositService;
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

final class TransferServiceTest extends TestCase
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
                'transfer.source@integration.wallet.local',
                'transfer.destination@integration.wallet.local'
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
                'transfer.source@integration.wallet.local',
                'transfer.destination@integration.wallet.local'
            )
        )
        OR destination_account_id IN (
            SELECT a.id
            FROM accounts a
            INNER JOIN users u
                ON u.id = a.user_id
            WHERE u.email IN (
                'transfer.source@integration.wallet.local',
                'transfer.destination@integration.wallet.local'
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
                'transfer.source@integration.wallet.local',
                'transfer.destination@integration.wallet.local'
            )
        )
        "
        );

        $this->connection->exec(
            "
        DELETE FROM users
        WHERE email IN (
            'transfer.source@integration.wallet.local',
            'transfer.destination@integration.wallet.local'
        )
        "
        );

        $publicIdGenerator = new PublicIdGenerator();
        $userRepository = new UserRepository($this->connection);
        $accountRepository = new AccountRepository($this->connection);
        $accountNumberGenerator = new AccountNumberGenerator();

        $this->sourceUserId = $userRepository->create(
            $publicIdGenerator->generate(),
            'Transfer Source User',
            'transfer.source@integration.wallet.local',
            password_hash('SenhaTeste123!', PASSWORD_DEFAULT)
        );

        $this->destinationUserId = $userRepository->create(
            $publicIdGenerator->generate(),
            'Transfer Destination User',
            'transfer.destination@integration.wallet.local',
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
            'INTEGRATION-TRANSFER-FUND-001',
            $this->sourceUserId
        );
    }

    public function testTransferMovesMoneyAndCreatesDoubleLedgerEntry(): void
    {
        $service = new TransferService(
            $this->connection,
            new AccountRepository($this->connection),
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            new PublicIdGenerator(),
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        $result = $service->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
            $this->sourceUserId,
            'SenhaTeste123!',
            'INTEGRATION-TRANSFER-001'
        );

        self::assertSame('100.00', $result['source_balance_before']);
        self::assertSame('74.50', $result['source_balance_after']);
        self::assertSame('0.00', $result['destination_balance_before']);
        self::assertSame('25.50', $result['destination_balance_after']);

        $statement = $this->connection->prepare(
            '
            SELECT id, balance
            FROM accounts
            WHERE id IN (:source_id, :destination_id)
            ORDER BY id
            '
        );

        $statement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($statement->fetchAll() as $account) {
            $accounts[(int) $account['id']] = (string) $account['balance'];
        }

        self::assertSame('74.50', $accounts[$this->sourceAccountId]);
        self::assertSame('25.50', $accounts[$this->destinationAccountId]);

        $operationStatement = $this->connection->prepare(
            '
            SELECT *
            FROM operations
            WHERE id = :id
            '
        );

        $operationStatement->execute([
            'id' => $result['operation_id'],
        ]);

        $operation = $operationStatement->fetch();

        self::assertNotFalse($operation);
        self::assertSame('TRANSFER', $operation['type']);
        self::assertSame('COMPLETED', $operation['status']);
        self::assertSame('25.50', (string) $operation['amount']);
        self::assertSame(
            $this->sourceAccountId,
            (int) $operation['source_account_id']
        );
        self::assertSame(
            $this->destinationAccountId,
            (int) $operation['destination_account_id']
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
            'operation_id' => $result['operation_id'],
        ]);

        $entries = $ledgerStatement->fetchAll();

        self::assertCount(2, $entries);

        self::assertSame('DEBIT', $entries[0]['entry_type']);
        self::assertSame('25.50', (string) $entries[0]['amount']);
        self::assertSame('100.00', (string) $entries[0]['balance_before']);
        self::assertSame('74.50', (string) $entries[0]['balance_after']);

        self::assertSame('CREDIT', $entries[1]['entry_type']);
        self::assertSame('25.50', (string) $entries[1]['amount']);
        self::assertSame('0.00', (string) $entries[1]['balance_before']);
        self::assertSame('25.50', (string) $entries[1]['balance_after']);
    }

    public function testTransferWithInvalidPasswordDoesNotChangeFinancialState(): void
    {
        $service = new TransferService(
            $this->connection,
            new AccountRepository($this->connection),
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            new PublicIdGenerator(),
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        try {
            $service->execute(
                $this->sourceAccountId,
                $this->destinationAccountId,
                '25.50',
                $this->sourceUserId,
                'SenhaErrada123!',
                'INTEGRATION-TRANSFER-INVALID-PASSWORD'
            );

            self::fail('Expected invalid transaction authorization.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Invalid transaction authorization.',
                $exception->getMessage()
            );
        }

        $statement = $this->connection->prepare(
            '
        SELECT id, balance
        FROM accounts
        WHERE id IN (:source_id, :destination_id)
        '
        );

        $statement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($statement->fetchAll() as $account) {
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
        SELECT COUNT(*)
        FROM operations
        WHERE type = :type
          AND source_account_id = :source_id
          AND destination_account_id = :destination_id
        '
        );

        $operationStatement->execute([
            'type' => 'TRANSFER',
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        self::assertSame(
            0,
            (int) $operationStatement->fetchColumn()
        );
    }

    public function testUserCannotTransferFromAccountTheyDoNotOwn(): void
    {
        $service = new TransferService(
            $this->connection,
            new AccountRepository($this->connection),
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            new PublicIdGenerator(),
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        try {
            $service->execute(
                $this->sourceAccountId,
                $this->destinationAccountId,
                '25.50',
                $this->destinationUserId,
                'SenhaTeste123!',
                'INTEGRATION-TRANSFER-UNAUTHORIZED-OWNER'
            );

            self::fail('Expected account ownership validation to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'User is not authorized to operate this account.',
                $exception->getMessage()
            );
        }

        $statement = $this->connection->prepare(
            '
        SELECT id, balance
        FROM accounts
        WHERE id IN (:source_id, :destination_id)
        '
        );

        $statement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($statement->fetchAll() as $account) {
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
        SELECT COUNT(*)
        FROM operations
        WHERE type = :type
          AND source_account_id = :source_id
          AND destination_account_id = :destination_id
        '
        );

        $operationStatement->execute([
            'type' => 'TRANSFER',
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        self::assertSame(
            0,
            (int) $operationStatement->fetchColumn()
        );
    }

    public function testTransferWithInsufficientBalanceDoesNotChangeFinancialState(): void
    {
        $service = new TransferService(
            $this->connection,
            new AccountRepository($this->connection),
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            new PublicIdGenerator(),
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        try {
            $service->execute(
                $this->sourceAccountId,
                $this->destinationAccountId,
                '150.00',
                $this->sourceUserId,
                'SenhaTeste123!',
                'INTEGRATION-TRANSFER-INSUFFICIENT'
            );

            self::fail('Expected insufficient balance to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Insufficient balance.',
                $exception->getMessage()
            );
        }

        $statement = $this->connection->prepare(
            '
        SELECT id, balance
        FROM accounts
        WHERE id IN (:source_id, :destination_id)
        '
        );

        $statement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($statement->fetchAll() as $account) {
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
        SELECT COUNT(*)
        FROM operations
        WHERE type = :type
          AND source_account_id = :source_id
          AND destination_account_id = :destination_id
        '
        );

        $operationStatement->execute([
            'type' => 'TRANSFER',
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        self::assertSame(
            0,
            (int) $operationStatement->fetchColumn()
        );

        $ledgerStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM ledger_entries
        WHERE account_id IN (:source_id, :destination_id)
          AND operation_id IN (
              SELECT id
              FROM operations
              WHERE type = :type
          )
        '
        );

        $ledgerStatement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
            'type' => 'TRANSFER',
        ]);

        self::assertSame(
            0,
            (int) $ledgerStatement->fetchColumn()
        );
    }

    public function testRepeatedTransferWithSameIdempotencyKeyDoesNotMoveMoneyTwice(): void
    {
        $service = new TransferService(
            $this->connection,
            new AccountRepository($this->connection),
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            new PublicIdGenerator(),
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        $idempotencyKey = 'INTEGRATION-TRANSFER-IDEMPOTENT-001';

        $firstResult = $service->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
            $this->sourceUserId,
            'SenhaTeste123!',
            $idempotencyKey
        );

        $secondResult = $service->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
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
            2,
            (int) $ledgerStatement->fetchColumn()
        );
    }

    public function testSameIdempotencyKeyCannotBeReusedForDifferentTransfer(): void
    {
        $service = new TransferService(
            $this->connection,
            new AccountRepository($this->connection),
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            new PublicIdGenerator(),
            new PasswordTransactionAuthorizer(
                new UserRepository($this->connection)
            )
        );

        $idempotencyKey = 'INTEGRATION-TRANSFER-IDEMPOTENT-CONFLICT';

        $service->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
            $this->sourceUserId,
            'SenhaTeste123!',
            $idempotencyKey
        );

        try {
            $service->execute(
                $this->sourceAccountId,
                $this->destinationAccountId,
                '30.00',
                $this->sourceUserId,
                'SenhaTeste123!',
                $idempotencyKey
            );

            self::fail('Expected idempotency conflict.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Idempotency key already used for another operation.',
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
    }

    public function testConcurrentTransfersCannotSpendSameBalanceTwice(): void
    {
        $startFile = sys_get_temp_dir()
            . '/wallet-transfer-concurrency-'
            . bin2hex(random_bytes(8));

        $runTransfer = function (
            string $idempotencyKey
        ) use ($startFile): never {
            while (!file_exists($startFile)) {
                usleep(1000);
            }

            try {
                $connection = ConnectionFactory::create();

                $service = new TransferService(
                    $connection,
                    new AccountRepository($connection),
                    new OperationRepository($connection),
                    new LedgerEntryRepository($connection),
                    new PublicIdGenerator(),
                    new PasswordTransactionAuthorizer(
                        new UserRepository($connection)
                    )
                );

                $service->execute(
                    $this->sourceAccountId,
                    $this->destinationAccountId,
                    '80.00',
                    $this->sourceUserId,
                    'SenhaTeste123!',
                    $idempotencyKey
                );

                exit(0);
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() === 'Insufficient balance.') {
                    exit(2);
                }

                exit(3);
            } catch (\Throwable) {
                exit(4);
            }
        };

        $firstPid = pcntl_fork();

        self::assertNotSame(-1, $firstPid);

        if ($firstPid === 0) {
            $runTransfer('INTEGRATION-CONCURRENT-TRANSFER-001');
        }

        $secondPid = pcntl_fork();

        self::assertNotSame(-1, $secondPid);

        if ($secondPid === 0) {
            $runTransfer('INTEGRATION-CONCURRENT-TRANSFER-002');
        }

        usleep(100000);

        file_put_contents($startFile, 'start');

        pcntl_waitpid($firstPid, $firstStatus);
        pcntl_waitpid($secondPid, $secondStatus);

        @unlink($startFile);

        $this->connection = ConnectionFactory::create();

        self::assertTrue(pcntl_wifexited($firstStatus));
        self::assertTrue(pcntl_wifexited($secondStatus));

        $exitCodes = [
            pcntl_wexitstatus($firstStatus),
            pcntl_wexitstatus($secondStatus),
        ];

        sort($exitCodes);

        self::assertSame([0, 2], $exitCodes);

        $statement = $this->connection->prepare(
            '
        SELECT id, balance
        FROM accounts
        WHERE id IN (:source_id, :destination_id)
        '
        );

        $statement->execute([
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        $accounts = [];

        foreach ($statement->fetchAll() as $account) {
            $accounts[(int) $account['id']] = (string) $account['balance'];
        }

        self::assertSame(
            '20.00',
            $accounts[$this->sourceAccountId]
        );

        self::assertSame(
            '80.00',
            $accounts[$this->destinationAccountId]
        );

        $operationStatement = $this->connection->prepare(
            '
        SELECT COUNT(*)
        FROM operations
        WHERE type = :type
          AND source_account_id = :source_id
          AND destination_account_id = :destination_id
        '
        );

        $operationStatement->execute([
            'type' => 'TRANSFER',
            'source_id' => $this->sourceAccountId,
            'destination_id' => $this->destinationAccountId,
        ]);

        self::assertSame(
            1,
            (int) $operationStatement->fetchColumn()
        );
    }
}
