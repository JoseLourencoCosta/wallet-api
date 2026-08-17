<?php

declare(strict_types=1);

namespace App\Domain\Account;

final class AccountNumberGenerator
{
    public function generate(): array
    {
        $number = (string) random_int(100000, 999999);
        $digit = $this->calculateDigit($number);

        return [
            'number' => $number,
            'digit' => $digit,
        ];
    }

    public function isValid(string $number, string $digit): bool
    {
        if (!preg_match('/^\d{6}$/', $number)) {
            return false;
        }

        if (!preg_match('/^\d$/', $digit)) {
            return false;
        }

        return $this->calculateDigit($number) === $digit;
    }

    private function calculateDigit(string $number): string
    {
        $sum = 0;
        $weight = 2;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum += ((int) $number[$i]) * $weight;

            $weight++;

            if ($weight > 9) {
                $weight = 2;
            }
        }

        $remainder = $sum % 11;
        $digit = 11 - $remainder;

        if ($digit >= 10) {
            $digit = 0;
        }

        return (string) $digit;
    }
}