<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Enum;

use Koh\StructuredContent\Domain\Enum\RelationType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelationTypeTest extends TestCase
{
    #[Test]
    public function testAllCasesHaveStringValues(): void
    {
        self::assertSame('related_to', RelationType::RELATED_TO->value);
        self::assertSame('replaces', RelationType::REPLACES->value);
        self::assertSame('requires', RelationType::REQUIRES->value);
        self::assertSame('explains', RelationType::EXPLAINS->value);
        self::assertSame('belongs_to', RelationType::BELONGS_TO->value);
        self::assertSame('contradicts', RelationType::CONTRADICTS->value);
        self::assertSame('alternative_to', RelationType::ALTERNATIVE_TO->value);
    }

    #[Test]
    public function testCaseCount(): void
    {
        self::assertCount(7, RelationType::cases());
    }

    #[Test]
    public function testIsUnidirectionalReturnsTrueForDirectionalTypes(): void
    {
        self::assertTrue(RelationType::REPLACES->isUnidirectional());
        self::assertTrue(RelationType::REQUIRES->isUnidirectional());
        self::assertTrue(RelationType::EXPLAINS->isUnidirectional());
        self::assertTrue(RelationType::BELONGS_TO->isUnidirectional());
    }

    #[Test]
    public function testIsUnidirectionalReturnsFalseForBidirectionalTypes(): void
    {
        self::assertFalse(RelationType::RELATED_TO->isUnidirectional());
        self::assertFalse(RelationType::CONTRADICTS->isUnidirectional());
        self::assertFalse(RelationType::ALTERNATIVE_TO->isUnidirectional());
    }

    #[Test]
    public function testIsBidirectionalReturnsTrueForBidirectionalTypes(): void
    {
        self::assertTrue(RelationType::RELATED_TO->isBidirectional());
        self::assertTrue(RelationType::CONTRADICTS->isBidirectional());
        self::assertTrue(RelationType::ALTERNATIVE_TO->isBidirectional());
    }

    #[Test]
    public function testIsBidirectionalReturnsFalseForUnidirectionalTypes(): void
    {
        self::assertFalse(RelationType::REPLACES->isBidirectional());
        self::assertFalse(RelationType::REQUIRES->isBidirectional());
        self::assertFalse(RelationType::EXPLAINS->isBidirectional());
        self::assertFalse(RelationType::BELONGS_TO->isBidirectional());
    }

    #[Test]
    public function testLabelReturnsHumanReadableString(): void
    {
        self::assertSame('Related To', RelationType::RELATED_TO->label());
        self::assertSame('Replaces', RelationType::REPLACES->label());
        self::assertSame('Requires', RelationType::REQUIRES->label());
        self::assertSame('Explains', RelationType::EXPLAINS->label());
        self::assertSame('Belongs To', RelationType::BELONGS_TO->label());
        self::assertSame('Contradicts', RelationType::CONTRADICTS->label());
        self::assertSame('Alternative To', RelationType::ALTERNATIVE_TO->label());
    }
}
