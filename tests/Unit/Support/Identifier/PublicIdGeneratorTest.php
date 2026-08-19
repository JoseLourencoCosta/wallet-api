<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Identifier;

use App\Support\Identifier\PublicIdGenerator;
use PHPUnit\Framework\TestCase;

final class PublicIdGeneratorTest extends TestCase
{
    public function testGeneratedPublicIdHasTwentySixCharacters(): void
    {
        $generator = new PublicIdGenerator();

        $publicId = $generator->generate();

        self::assertSame(26, strlen($publicId));
    }

    public function testGeneratedPublicIdsAreDifferent(): void
    {
        $generator = new PublicIdGenerator();

        $first = $generator->generate();
        $second = $generator->generate();

        self::assertNotSame($first, $second);
    }

    public function testGeneratedPublicIdContainsOnlyValidUlidCharacters(): void
    {
        $generator = new PublicIdGenerator();

        $publicId = $generator->generate();

        self::assertMatchesRegularExpression(
            '/^[0-9A-HJKMNP-TV-Z]{26}$/',
            $publicId
        );
    }
}