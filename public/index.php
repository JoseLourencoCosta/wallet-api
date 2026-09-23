<?php

declare(strict_types=1);

use App\Application\User\RegisterUser;
use App\Domain\Account\AccountNumberGenerator;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

$connection = ConnectionFactory::create();

$registerUser = new RegisterUser(
    $connection,
    new UserRepository($connection),
    new AccountRepository($connection),
    new PublicIdGenerator(),
    new AccountNumberGenerator()
);

$request = Request::fromGlobals();

$router = new Router(
    $registerUser
);

$response = $router->dispatch(
    $request
);

$response->send();
