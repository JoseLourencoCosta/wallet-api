<?php

declare(strict_types=1);

namespace Tests\Integration\Application\User;

use App\Application\User\AuthenticateUser;
use App\Domain\User\InvalidCredentials;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthenticateUserTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create();

        $this->connection->exec(
            "
            DELETE FROM users
            WHERE email LIKE '%@auth.integration.wallet.local'
            "
        );
    }

    public function testValidCredentialsAuthenticateUser(): void
    {
        $repository = new UserRepository(
            $this->connection
        );

        $repository->create(
            (new PublicIdGenerator())->generate(),
            'Auth User',
            'success@auth.integration.wallet.local',
            password_hash(
                'SenhaTeste123!',
                PASSWORD_DEFAULT
            )
        );

        $authenticateUser = new AuthenticateUser(
            $repository
        );

        $result = $authenticateUser->execute(
            'success@auth.integration.wallet.local',
            'SenhaTeste123!'
        );

        self::assertSame(
            'Auth User',
            $result['name']
        );

        self::assertSame(
            'success@auth.integration.wallet.local',
            $result['email']
        );

        self::assertArrayHasKey(
            'user_id',
            $result
        );

        self::assertArrayHasKey(
            'user_public_id',
            $result
        );
    }

    public function testInvalidPasswordThrowsInvalidCredentials(): void
    {
        $repository = new UserRepository(
            $this->connection
        );

        $repository->create(
            (new PublicIdGenerator())->generate(),
            'Auth User',
            'invalid-password@auth.integration.wallet.local',
            password_hash(
                'SenhaTeste123!',
                PASSWORD_DEFAULT
            )
        );

        $authenticateUser = new AuthenticateUser(
            $repository
        );

        $this->expectException(
            InvalidCredentials::class
        );

        $authenticateUser->execute(
            'invalid-password@auth.integration.wallet.local',
            'SenhaErrada'
        );
    }

    public function testUnknownEmailThrowsInvalidCredentials(): void
    {
        $authenticateUser = new AuthenticateUser(
            new UserRepository(
                $this->connection
            )
        );

        $this->expectException(
            InvalidCredentials::class
        );

        $authenticateUser->execute(
            'unknown@auth.integration.wallet.local',
            'SenhaTeste123!'
        );
    }
}
