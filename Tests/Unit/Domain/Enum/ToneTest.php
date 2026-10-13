<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Enum;

use Koh\StructuredContent\Domain\Enum\Tone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToneTest extends TestCase
{
    #[Test]
    public function testAllCasesHaveStringValues(): void
    {
        self::assertSame('informational', Tone::INFORMATIONAL->value);
        self::assertSame('promotional', Tone::PROMOTIONAL->value);
        self::assertSame('technical', Tone::TECHNICAL->value);
        self::assertSame('legal', Tone::LEGAL->value);
    }

    #[Test]
    public function testCaseCount(): void
    {
        self::assertCount(4, Tone::cases());
    }
}
