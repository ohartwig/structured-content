<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Enum;

use Koh\StructuredContent\Domain\Enum\Audience;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AudienceTest extends TestCase
{
    #[Test]
    public function testAllCasesHaveStringValues(): void
    {
        self::assertSame('customer', Audience::CUSTOMER->value);
        self::assertSame('partner', Audience::PARTNER->value);
        self::assertSame('internal', Audience::INTERNAL->value);
        self::assertSame('developer', Audience::DEVELOPER->value);
    }

    #[Test]
    public function testCaseCount(): void
    {
        self::assertCount(4, Audience::cases());
    }
}
