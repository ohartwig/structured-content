<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\ContextResolver;
use Koh\StructuredContent\Domain\Contract\ContextConfigurationProviderInterface;
use Koh\StructuredContent\Domain\Contract\PageRootlineProviderInterface;
use Koh\StructuredContent\Domain\Model\ResolvedContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextResolverTest extends TestCase
{
    private ContextConfigurationProviderInterface $configProvider;
    private PageRootlineProviderInterface $rootlineProvider;
    private ContextResolver $resolver;

    protected function setUp(): void
    {
        $this->configProvider = $this->createMock(ContextConfigurationProviderInterface::class);
        $this->configProvider->method('getDefaultAudience')->willReturn('customer');
        $this->configProvider->method('getDefaultTone')->willReturn('informational');
        $this->configProvider->method('getDefaultChannels')->willReturn(['web_chat']);

        $this->rootlineProvider = $this->createMock(PageRootlineProviderInterface::class);
        $this->rootlineProvider->method('getRootline')->willReturn([]);
    }

    private function createResolver(?PageRootlineProviderInterface $rootlineProvider = null): ContextResolver
    {
        return new ContextResolver(
            configurationProvider: $this->configProvider,
            pageRootlineProvider: $rootlineProvider ?? $this->rootlineProvider,
        );
    }

    #[Test]
    public function testResolveUsesRecordFieldsWhenPresent(): void
    {
        $this->resolver = $this->createResolver();

        $record = [
            'uid' => 1,
            'pid' => 0,
            'ai_context_audience' => 'developer',
            'ai_context_tone' => 'technical',
            'ai_context_channels' => 'email,phone',
        ];

        $result = $this->resolver->resolve(table: 'tt_content', record: $record);

        self::assertInstanceOf(ResolvedContext::class, $result);
        self::assertSame('developer', $result->audience);
        self::assertSame('technical', $result->tone);
        self::assertSame(['email', 'phone'], $result->channels);
        self::assertSame('record', $result->inheritedFrom);
    }

    #[Test]
    public function testResolveUsesDefaultsWhenRecordFieldsAreEmpty(): void
    {
        $this->resolver = $this->createResolver();

        $record = [
            'uid' => 1,
            'pid' => 0,
            'ai_context_audience' => '',
            'ai_context_tone' => '',
            'ai_context_channels' => '',
        ];

        $result = $this->resolver->resolve(table: 'tt_content', record: $record);

        self::assertSame('customer', $result->audience);
        self::assertSame('informational', $result->tone);
        self::assertSame(['web_chat'], $result->channels);
        self::assertSame('defaults', $result->inheritedFrom);
    }

    #[Test]
    public function testResolveUsesDefaultsWhenFieldsMissing(): void
    {
        $this->resolver = $this->createResolver();

        $record = [
            'uid' => 1,
            'pid' => 0,
        ];

        $result = $this->resolver->resolve(table: 'tt_content', record: $record);

        self::assertSame('customer', $result->audience);
        self::assertSame('informational', $result->tone);
        self::assertSame(['web_chat'], $result->channels);
        self::assertSame('defaults', $result->inheritedFrom);
    }

    #[Test]
    public function testParseChannelsSplitsCommaSeparatedString(): void
    {
        $this->resolver = $this->createResolver();

        $result = $this->resolver->parseChannels('web_chat, email , phone');

        self::assertSame(['web_chat', 'email', 'phone'], $result);
    }

    #[Test]
    public function testParseChannelsReturnsEmptyArrayForEmptyString(): void
    {
        $this->resolver = $this->createResolver();

        $result = $this->resolver->parseChannels('');

        self::assertSame([], $result);
    }

    #[Test]
    public function testParseChannelsFiltersEmptyEntries(): void
    {
        $this->resolver = $this->createResolver();

        $result = $this->resolver->parseChannels('web_chat,,email,,,phone');

        self::assertSame(['web_chat', 'email', 'phone'], $result);
    }

    #[Test]
    public function testResolvePartialOverride(): void
    {
        $this->resolver = $this->createResolver();

        $record = [
            'uid' => 1,
            'pid' => 0,
            'ai_context_audience' => 'partner',
            'ai_context_tone' => '',
            'ai_context_channels' => '',
        ];

        $result = $this->resolver->resolve(table: 'pages', record: $record);

        self::assertSame('partner', $result->audience);
        self::assertSame('informational', $result->tone);
        self::assertSame(['web_chat'], $result->channels);
        self::assertSame('record', $result->inheritedFrom);
    }

    #[Test]
    public function testResolveFromPageTreeWalksRootlineAndInherits(): void
    {
        $rootlineProvider = $this->createMock(PageRootlineProviderInterface::class);
        $rootlineProvider->method('getRootline')->with(10)->willReturn([
            // Current page (uid 10) — has audience only
            ['uid' => 10, 'pid' => 5, 'ai_context_audience' => 'developer', 'ai_context_tone' => '', 'ai_context_channels' => ''],
            // Parent page (uid 5) — has tone only
            ['uid' => 5, 'pid' => 1, 'ai_context_audience' => '', 'ai_context_tone' => 'technical', 'ai_context_channels' => ''],
            // Root page (uid 1) — has channels only
            ['uid' => 1, 'pid' => 0, 'ai_context_audience' => '', 'ai_context_tone' => '', 'ai_context_channels' => 'email,phone'],
        ]);

        $this->resolver = $this->createResolver(rootlineProvider: $rootlineProvider);

        $result = $this->resolver->resolveFromPageTree(pageUid: 10);

        self::assertSame('developer', $result->audience);
        self::assertSame('technical', $result->tone);
        self::assertSame(['email', 'phone'], $result->channels);
        self::assertSame('page', $result->inheritedFrom);
    }

    #[Test]
    public function testResolveFromPageTreeFallsBackToDefaults(): void
    {
        $rootlineProvider = $this->createMock(PageRootlineProviderInterface::class);
        $rootlineProvider->method('getRootline')->with(10)->willReturn([
            ['uid' => 10, 'pid' => 1, 'ai_context_audience' => '', 'ai_context_tone' => '', 'ai_context_channels' => ''],
            ['uid' => 1, 'pid' => 0, 'ai_context_audience' => '', 'ai_context_tone' => '', 'ai_context_channels' => ''],
        ]);

        $this->resolver = $this->createResolver(rootlineProvider: $rootlineProvider);

        $result = $this->resolver->resolveFromPageTree(pageUid: 10);

        self::assertSame('customer', $result->audience);
        self::assertSame('informational', $result->tone);
        self::assertSame(['web_chat'], $result->channels);
        self::assertSame('defaults', $result->inheritedFrom);
    }

    #[Test]
    public function testResolveFromPageTreeReturnsDefaultsWhenRootlineIsEmpty(): void
    {
        $rootlineProvider = $this->createMock(PageRootlineProviderInterface::class);
        $rootlineProvider->method('getRootline')->with(999)->willReturn([]);

        $this->resolver = $this->createResolver(rootlineProvider: $rootlineProvider);

        $result = $this->resolver->resolveFromPageTree(pageUid: 999);

        self::assertSame('customer', $result->audience);
        self::assertSame('informational', $result->tone);
        self::assertSame(['web_chat'], $result->channels);
        self::assertSame('defaults', $result->inheritedFrom);
    }

    #[Test]
    public function testResolveUsesPageTreeFallbackWhenRecordFieldsEmpty(): void
    {
        $rootlineProvider = $this->createMock(PageRootlineProviderInterface::class);
        $rootlineProvider->method('getRootline')->with(5)->willReturn([
            ['uid' => 5, 'pid' => 1, 'ai_context_audience' => 'partner', 'ai_context_tone' => 'persuasive', 'ai_context_channels' => 'social'],
        ]);

        $this->resolver = $this->createResolver(rootlineProvider: $rootlineProvider);

        $record = [
            'uid' => 42,
            'pid' => 5,
            'ai_context_audience' => '',
            'ai_context_tone' => '',
            'ai_context_channels' => '',
        ];

        $result = $this->resolver->resolve(table: 'tt_content', record: $record);

        self::assertSame('partner', $result->audience);
        self::assertSame('persuasive', $result->tone);
        self::assertSame(['social'], $result->channels);
        self::assertSame('page', $result->inheritedFrom);
    }

    #[Test]
    public function testResolveRecordOverridesTakesPriorityOverPageTree(): void
    {
        $rootlineProvider = $this->createMock(PageRootlineProviderInterface::class);
        $rootlineProvider->method('getRootline')->with(5)->willReturn([
            ['uid' => 5, 'pid' => 1, 'ai_context_audience' => 'partner', 'ai_context_tone' => 'persuasive', 'ai_context_channels' => 'social'],
        ]);

        $this->resolver = $this->createResolver(rootlineProvider: $rootlineProvider);

        $record = [
            'uid' => 42,
            'pid' => 5,
            'ai_context_audience' => 'developer',
            'ai_context_tone' => '',
            'ai_context_channels' => '',
        ];

        $result = $this->resolver->resolve(table: 'tt_content', record: $record);

        self::assertSame('developer', $result->audience);
        self::assertSame('persuasive', $result->tone);
        self::assertSame(['social'], $result->channels);
        self::assertSame('record', $result->inheritedFrom);
    }
}
