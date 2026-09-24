<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Http;

use App\Application\User\AuthenticateUser;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Http\Handler\LoginHandler;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Persistence\UserRepository;
use PHPUnit\Framework\TestCase;

final class LoginHandlerTest extends TestCase
{
    public function testValidCredentialsReturnUserBody(): void
    {
        $connection = ConnectionFactory::create();

        $handler = new LoginHandler(
            new AuthenticateUser(
                new UserRepository($connection)
            )
        );

        $request = new Request(
            'POST',
            '/login',
            [
                'email' => 'login.teste@example.com',
                'password' => 'SenhaTeste123!',
            ],
            false
        );

        $response = $handler->handle($request);

        self::assertSame(
            200,
            $response->statusCode()
        );

        self::assertSame(
            [
                'user' => [
                    'id' => '01M38DXCM3T348MQB4MAHWFC9D',
                    'name' => 'Login Teste',
                    'email' => 'login.teste@example.com',
                ],
            ],
            $response->body()
        );
    }
}
