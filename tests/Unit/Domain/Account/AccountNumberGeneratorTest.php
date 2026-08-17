<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\AccountNumberGenerator;
use PHPUnit\Framework\TestCase;

final class AccountNumberGeneratorTest extends TestCase
{
    public function testGeneratedAccountHasSixDigitsAndValidDigit(): void
    {
        $generator = new AccountNumberGenerator();

        $account = $generator->generate();

        self::assertMatchesRegularExpression('/^\d{6}$/', $account['number']);
        self::assertMatchesRegularExpression('/^\d$/', $account['digit']);
        self::assertTrue(
            $generator->isValid(
                $account['number'],
                $account['digit']
            )
        );
    }

    public function testValidAccountNumberReturnsTrue(): void
    {
        $generator = new AccountNumberGenerator();

        self::assertTrue(
            $generator->isValid('470616', '1')
        );
    }

    public function testInvalidDigitReturnsFalse(): void
    {
        $generator = new AccountNumberGenerator();

        self::assertFalse(
            $generator->isValid('470616', '9')
        );
    }

    public function testInvalidAccountNumberFormatReturnsFalse(): void
    {
        $generator = new AccountNumberGenerator();

        self::assertFalse(
            $generator->isValid('12345', '1')
        );

        self::assertFalse(
            $generator->isValid('ABC123', '1')
        );
    }
}