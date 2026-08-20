<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Domain\Account\AccountNumberGenerator;
use App\Infrastructure\Persistence\AccountRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Identifier\PublicIdGenerator;
use PDO;
use Throwable;

final class RegisterUser
{
    private const AGENCY = '0001';

    public function __construct(
        private PDO $connection,
        private UserRepository $userRepository,
        private AccountRepository $accountRepository,
        private PublicIdGenerator $publicIdGenerator,
        private AccountNumberGenerator $accountNumberGenerator
    ) {
    }

    public function execute(
        string $name,
        string $email,
        string $password
    ): array {
        $this->connection->beginTransaction();

        try {
            $userPublicId = $this->publicIdGenerator->generate();
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $userId = $this->userRepository->create(
                $userPublicId,
                $name,
                $email,
                $passwordHash
            );

            $account = $this->accountNumberGenerator->generate();
            $accountPublicId = $this->publicIdGenerator->generate();

            $accountId = $this->accountRepository->create(
                $accountPublicId,
                $userId,
                self::AGENCY,
                $account['number'],
                $account['digit']
            );

            $this->connection->commit();

            return [
                'user_id' => $userId,
                'user_public_id' => $userPublicId,
                'account_id' => $accountId,
                'account_public_id' => $accountPublicId,
                'agency' => self::AGENCY,
                'account_number' => $account['number'],
                'account_digit' => $account['digit'],
            ];
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}