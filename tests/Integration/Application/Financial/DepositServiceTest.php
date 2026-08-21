<?php

declare(strict_types=1);

namespace Tests\Integration\Application\Financial;

use App\Application\Financial\DepositService;
use App\Domain\Account\AccountNumberGenerator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\LedgerEntryRepository;
use App\Infrastructure\Persistence\OperationRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

final class DepositServiceTest extends TestCase
{
    private PDO $connection;
    private int $userId;
    private int $accountId;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create();

        $this->connection->exec(
            "DELETE FROM ledger_entries
             WHERE operation_id IN (
                 SELECT id
                 FROM operations
                 WHERE reference_id LIKE 'INTEGRATION-DEPOSIT-%'
             )"
        );

        $this->connection->exec(
            "DELETE FROM operations
             WHERE reference_id LIKE 'INTEGRATION-DEPOSIT-%'"
        );

        $this->connection->exec(
            "DELETE FROM accounts
             WHERE user_id IN (
                 SELECT id
                 FROM users
                 WHERE email = 'deposit@integration.wallet.local'
             )"
        );

        $this->connection->exec(
            "DELETE FROM users
             WHERE email = 'deposit@integration.wallet.local'"
        );

        $publicIdGenerator = new PublicIdGenerator();

        $userRepository = new UserRepository($this->connection);

        $this->userId = $userRepository->create(
            $publicIdGenerator->generate(),
            'Deposit Integration User',
            'deposit@integration.wallet.local',
            password_hash('SenhaTeste123!', PASSWORD_DEFAULT)
        );

        $accountNumberGenerator = new AccountNumberGenerator();
        $account = $accountNumberGenerator->generate();

        $accountRepository = new AccountRepository($this->connection);

        $this->accountId = $accountRepository->create(
            $publicIdGenerator->generate(),
            $this->userId,
            '0001',
            $account['number'],
            $account['digit']
        );
    }

    public function testDepositUpdatesBalanceAndCreatesFinancialHistory(): void
    {
        $service = new DepositService(
            $this->connection,
            new AccountRepository($this->connection),
            new OperationRepository($this->connection),
            new LedgerEntryRepository($this->connection),
            new PublicIdGenerator()
        );

        $result = $service->execute(
            $this->accountId,
            '125.50',
            'INTEGRATION-DEPOSIT-001',
            $this->userId
        );

        self::assertSame('0.00', $result['balance_before']);
        self::assertSame('125.50', $result['balance_after']);

        $balanceStatement = $this->connection->prepare(
            'SELECT balance FROM accounts WHERE id = :id'
        );

        $balanceStatement->execute([
            'id' => $this->accountId,
        ]);

        self::assertSame(
            '125.50',
            (string) $balanceStatement->fetchColumn()
        );

        $operationStatement = $this->connection->prepare(
            '
            SELECT
                id,
                type,
                status,
                amount,
                destination_account_id,
                actor_id,
                reference_id
            FROM operations
            WHERE reference_id = :reference_id
            '
        );

        $operationStatement->execute([
            'reference_id' => 'INTEGRATION-DEPOSIT-001',
        ]);

        $operation = $operationStatement->fetch();

        self::assertNotFalse($operation);
        self::assertSame('DEPOSIT', $operation['type']);
        self::assertSame('COMPLETED', $operation['status']);
        self::assertSame('125.50', (string) $operation['amount']);
        self::assertSame(
            $this->accountId,
            (int) $operation['destination_account_id']
        );
        self::assertSame(
            $this->userId,
            (int) $operation['actor_id']
        );

        $ledgerStatement = $this->connection->prepare(
            '
            SELECT
                entry_type,
                amount,
                balance_before,
                balance_after
            FROM ledger_entries
            WHERE operation_id = :operation_id
            '
        );

        $ledgerStatement->execute([
            'operation_id' => $operation['id'],
        ]);

        $ledger = $ledgerStatement->fetch();

        self::assertNotFalse($ledger);
        self::assertSame('CREDIT', $ledger['entry_type']);
        self::assertSame('125.50', (string) $ledger['amount']);
        self::assertSame('0.00', (string) $ledger['balance_before']);
        self::assertSame('125.50', (string) $ledger['balance_after']);
    }

    public function testInvalidDepositDoesNotChangeFinancialState(): void
{
    $service = new DepositService(
        $this->connection,
        new AccountRepository($this->connection),
        new OperationRepository($this->connection),
        new LedgerEntryRepository($this->connection),
        new PublicIdGenerator()
    );

    try {
        $service->execute(
            $this->accountId,
            '0.00',
            'INTEGRATION-DEPOSIT-INVALID',
            $this->userId
        );

        self::fail('Expected invalid deposit to fail.');
    } catch (\InvalidArgumentException) {
        // comportamento esperado
    }

    $balanceStatement = $this->connection->prepare(
        'SELECT balance FROM accounts WHERE id = :id'
    );

    $balanceStatement->execute([
        'id' => $this->accountId,
    ]);

    self::assertSame(
        '0.00',
        (string) $balanceStatement->fetchColumn()
    );

    $operationStatement = $this->connection->prepare(
        '
        SELECT COUNT(*)
        FROM operations
        WHERE reference_id = :reference_id
        '
    );

    $operationStatement->execute([
        'reference_id' => 'INTEGRATION-DEPOSIT-INVALID',
    ]);

    self::assertSame(
        0,
        (int) $operationStatement->fetchColumn()
    );

    $ledgerStatement = $this->connection->prepare(
        '
        SELECT COUNT(*)
        FROM ledger_entries
        WHERE account_id = :account_id
        '
    );

    $ledgerStatement->execute([
        'account_id' => $this->accountId,
    ]);

    self::assertSame(
        0,
        (int) $ledgerStatement->fetchColumn()
    );
}
}