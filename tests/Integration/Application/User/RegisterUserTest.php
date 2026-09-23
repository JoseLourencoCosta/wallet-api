<?php

declare(strict_types=1);

namespace Tests\Integration\Application\User;

use App\Application\User\RegisterUser;
use App\Domain\Account\AccountNumberGenerator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

final class RegisterUserTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create();

        $this->connection->exec(
            "
    DELETE FROM ledger_entries
    WHERE operation_id IN (
        SELECT id
        FROM operations
        WHERE source_account_id IN (
            SELECT a.id
            FROM accounts a
            INNER JOIN users u
                ON u.id = a.user_id
            WHERE u.email LIKE '%@integration.wallet.local'
        )
        OR destination_account_id IN (
            SELECT a.id
            FROM accounts a
            INNER JOIN users u
                ON u.id = a.user_id
            WHERE u.email LIKE '%@integration.wallet.local'
        )
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
            WHERE u.email LIKE '%@integration.wallet.local'
        )
        OR destination_account_id IN (
            SELECT a.id
            FROM accounts a
            INNER JOIN users u
                ON u.id = a.user_id
            WHERE u.email LIKE '%@integration.wallet.local'
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
            WHERE u.email LIKE '%@integration.wallet.local'
        )
        OR destination_account_id IN (
            SELECT a.id
            FROM accounts a
            INNER JOIN users u
                ON u.id = a.user_id
            WHERE u.email LIKE '%@integration.wallet.local'
        )
        "
        );

        $this->connection->exec(
            "
        DELETE FROM accounts
        WHERE user_id IN (
            SELECT id
            FROM users
            WHERE email LIKE '%@integration.wallet.local'
        )
        "
        );

        $this->connection->exec(
            "
        DELETE FROM users
        WHERE email LIKE '%@integration.wallet.local'
        "
        );
    }

    public function testUserAndAccountAreCreatedTogether(): void
    {
        $registerUser = $this->createRegisterUser();

        $result = $registerUser->execute(
            'Integration User',
            'success@integration.wallet.local',
            'SenhaTeste123!'
        );

        $statement = $this->connection->prepare(
            '
            SELECT
                u.id AS user_id,
                a.id AS account_id,
                a.user_id AS account_user_id
            FROM users u
            INNER JOIN accounts a
                ON a.user_id = u.id
            WHERE u.email = :email
            '
        );

        $statement->execute([
            'email' => 'success@integration.wallet.local',
        ]);

        $row = $statement->fetch();

        self::assertNotFalse($row);
        self::assertSame((int) $result['user_id'], (int) $row['user_id']);
        self::assertSame((int) $result['account_id'], (int) $row['account_id']);
        self::assertSame((int) $row['user_id'], (int) $row['account_user_id']);
    }

    public function testUserIsNotPersistedWhenAccountCreationFails(): void
    {
        $userRepository = new UserRepository($this->connection);

        $this->connection->beginTransaction();

        try {
            $userId = $userRepository->create(
                (new PublicIdGenerator())->generate(),
                'Rollback Integration User',
                'rollback@integration.wallet.local',
                password_hash('SenhaTeste123!', PASSWORD_DEFAULT)
            );

            $accountRepository = new AccountRepository($this->connection);

            $accountRepository->create(
                (new PublicIdGenerator())->generate(),
                $userId,
                '0001',
                '269079',
                '9'
            );

            $this->connection->commit();

            self::fail('Expected account creation to fail.');
        } catch (\Throwable) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
        }

        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM users WHERE email = :email'
        );

        $statement->execute([
            'email' => 'rollback@integration.wallet.local',
        ]);

        self::assertSame(
            0,
            (int) $statement->fetchColumn()
        );
    }

    private function createRegisterUser(): RegisterUser
    {
        return new RegisterUser(
            $this->connection,
            new UserRepository($this->connection),
            new AccountRepository($this->connection),
            new PublicIdGenerator(),
            new AccountNumberGenerator()
        );
    }
}
