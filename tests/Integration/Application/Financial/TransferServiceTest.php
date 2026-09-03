<?php

declare(strict_types=1);

namespace Tests\Integration\Application\Financial;

use App\Application\Financial\DepositService;
use App\Application\Financial\TransferService;
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
            new PublicIdGenerator()
        );

        $result = $service->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '25.50',
            $this->sourceUserId
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

    public function testTransferWithInsufficientBalanceDoesNotChangeFinancialState(): void
{
    $service = new TransferService(
        $this->connection,
        new AccountRepository($this->connection),
        new OperationRepository($this->connection),
        new LedgerEntryRepository($this->connection),
        new PublicIdGenerator()
    );

    try {
        $service->execute(
            $this->sourceAccountId,
            $this->destinationAccountId,
            '150.00',
            $this->sourceUserId
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
}
