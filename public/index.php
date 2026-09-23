<?php

declare(strict_types=1);

use App\Application\User\RegisterUser;
use App\Domain\Account\AccountNumberGenerator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Http\Handler\RegisterUserHandler;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::fromGlobals();

$registerUserHandlerFactory = static function (): RegisterUserHandler {
    $connection = ConnectionFactory::create();

    $registerUser = new RegisterUser(
        $connection,
        new UserRepository($connection),
        new AccountRepository($connection),
        new PublicIdGenerator(),
        new AccountNumberGenerator()
    );

    return new RegisterUserHandler(
        $registerUser
    );
};

try {
    $router = new Router(
        $registerUserHandlerFactory
    );

    $response = $router->dispatch(
        $request
    );

    $response->send();
} catch (\Throwable) {
    (new \App\Infrastructure\Http\JsonResponse(
        500,
        [
            'error' => 'Internal server error.',
        ]
    ))->send();
}
