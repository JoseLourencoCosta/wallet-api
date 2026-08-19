<?php

declare(strict_types=1);

namespace App\Support\Identifier;

use Symfony\Component\Uid\Ulid;

final class PublicIdGenerator
{
    public function generate(): string
    {
        return (string) new Ulid();
    }
}