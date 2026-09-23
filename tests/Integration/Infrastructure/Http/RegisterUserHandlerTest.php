<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Http;

use App\Application\User\RegisterUser;
use App\Domain\Account\AccountNumberGenerator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Http\Handler\RegisterUserHandler;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

final class RegisterUserHandlerTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create();

        $this->connection->exec(
            "
            DELETE FROM accounts
            WHERE user_id IN (
                SELECT id
                FROM users
                WHERE email LIKE '%@http.integration.wallet.local'
            )
            "
        );

        $this->connection->exec(
            "
            DELETE FROM users
            WHERE email LIKE '%@http.integration.wallet.local'
            "
        );
    }

    public function testValidRequestReturnsCreatedResponse(): void
    {
        $handler = $this->createHandler();

        $request = new Request(
            'POST',
            '/users',
            [
                'name' => 'HTTP Integration User',
                'email' => 'success@http.integration.wallet.local',
                'password' => 'SenhaTeste123!',
            ],
            false
        );

        $response = $handler->handle($request);

        self::assertSame(
            201,
            $response->statusCode()
        );

        self::assertSame(
            'HTTP Integration User',
            $response->body()['user']['name']
        );

        self::assertSame(
            'success@http.integration.wallet.local',
            $response->body()['user']['email']
        );

        self::assertArrayHasKey(
            'id',
            $response->body()['user']
        );

        self::assertArrayHasKey(
            'id',
            $response->body()['account']
        );

        self::assertSame(
            '0001',
            $response->body()['account']['agency']
        );
    }

    public function testInvalidJsonReturnsBadRequest(): void
    {
        $handler = $this->createHandler();

        $request = new Request(
            'POST',
            '/users',
            [],
            true
        );

        $response = $handler->handle($request);

        self::assertSame(
            400,
            $response->statusCode()
        );

        self::assertSame(
            [
                'error' => 'Invalid JSON body.',
            ],
            $response->body()
        );
    }

    public function testMissingRequiredFieldsReturnsUnprocessableEntity(): void
    {
        $handler = $this->createHandler();

        $request = new Request(
            'POST',
            '/users',
            [
                'name' => '',
                'email' => '',
                'password' => '',
            ],
            false
        );

        $response = $handler->handle($request);

        self::assertSame(
            422,
            $response->statusCode()
        );

        self::assertSame(
            [
                'error' => 'name, email and password are required.',
            ],
            $response->body()
        );
    }

    public function testInvalidEmailReturnsUnprocessableEntity(): void
    {
        $handler = $this->createHandler();

        $request = new Request(
            'POST',
            '/users',
            [
                'name' => 'Invalid Email User',
                'email' => 'email-invalido',
                'password' => 'SenhaTeste123!',
            ],
            false
        );

        $response = $handler->handle($request);

        self::assertSame(
            422,
            $response->statusCode()
        );

        self::assertSame(
            [
                'error' => 'Invalid email.',
            ],
            $response->body()
        );
    }

    public function testDuplicateEmailReturnsConflict(): void
    {
        $handler = $this->createHandler();

        $firstRequest = new Request(
            'POST',
            '/users',
            [
                'name' => 'First User',
                'email' => 'duplicate@http.integration.wallet.local',
                'password' => 'SenhaTeste123!',
            ],
            false
        );

        $firstResponse = $handler->handle(
            $firstRequest
        );

        self::assertSame(
            201,
            $firstResponse->statusCode()
        );

        $duplicateRequest = new Request(
            'POST',
            '/users',
            [
                'name' => 'Duplicate User',
                'email' => 'duplicate@http.integration.wallet.local',
                'password' => 'SenhaTeste123!',
            ],
            false
        );

        $duplicateResponse = $handler->handle(
            $duplicateRequest
        );

        self::assertSame(
            409,
            $duplicateResponse->statusCode()
        );

        self::assertSame(
            [
                'error' => 'Email already registered.',
            ],
            $duplicateResponse->body()
        );
    }

    private function createHandler(): RegisterUserHandler
    {
        $registerUser = new RegisterUser(
            $this->connection,
            new UserRepository($this->connection),
            new AccountRepository($this->connection),
            new PublicIdGenerator(),
            new AccountNumberGenerator()
        );

        return new RegisterUserHandler(
            $registerUser
        );
    }
}
