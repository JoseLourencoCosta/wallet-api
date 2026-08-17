<?php

declare(strict_types=1);

namespace App\Support;

final class HealthCheck
{
    public function status(): string
    {
        return 'OK';
    }
}