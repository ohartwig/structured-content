<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Presentation\EventListener;

use Koh\StructuredContent\Presentation\EventListener\PluginDetailIndexingListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Frontend\Event\AfterPageAndLanguageIsResolvedEvent;
use TYPO3\CMS\Frontend\Page\PageInformation;

final class PluginDetailIndexingListenerTest extends TestCase
{
    #[Test]
    public function testPersonDetailRouteClearsNoIndex(): void
    {
        $pageInformation = $this->dispatch(
            pageRecord: ['uid' => 889, 'no_index' => 1, 'no_follow' => 0],
            routeArguments: ['tx_contentblockscollection_person' => ['person' => 'kai-ole-hartwig']],
        );

        self::assertSame(0, $pageInformation->getPageRecord()['no_index']);
    }

    #[Test]
    public function testPodcastEpisodeDetailRouteClearsNoIndex(): void
    {
        $pageInformation = $this->dispatch(
            pageRecord: ['uid' => 746, 'no_index' => 1],
            routeArguments: ['tx_contentblockscollection_podcast' => ['series' => 'x', 'episode' => 'folge-11']],
        );

        self::assertSame(0, $pageInformation->getPageRecord()['no_index']);
    }

    #[Test]
    public function testOtherPageFieldsStayUntouched(): void
    {
        $pageRecord = ['uid' => 889, 'no_index' => 1, 'no_follow' => 1, 'title' => 'Autor'];

        $pageInformation = $this->dispatch(
            pageRecord: $pageRecord,
            routeArguments: ['tx_contentblockscollection_person' => ['person' => 'kai-ole-hartwig']],
        );

        self::assertSame(array_replace($pageRecord, ['no_index' => 0]), $pageInformation->getPageRecord());
    }

    #[Test]
    public function testContainerPageWithoutDetailArgumentsKeepsNoIndex(): void
    {
        $pageInformation = $this->dispatch(
            pageRecord: ['uid' => 889, 'no_index' => 1],
            routeArguments: [],
        );

        self::assertSame(1, $pageInformation->getPageRecord()['no_index'], 'Container/list pages must keep noindex');
    }

    #[Test]
    public function testPodcastSeriesRouteWithoutEpisodeKeepsNoIndex(): void
    {
        $pageInformation = $this->dispatch(
            pageRecord: ['uid' => 745, 'no_index' => 1],
            routeArguments: ['tx_contentblockscollection_podcast' => ['series' => 'no-bullshit-founders']],
        );

        self::assertSame(1, $pageInformation->getPageRecord()['no_index']);
    }

    #[Test]
    public function testEmptyPluginArgumentValueDoesNotTrigger(): void
    {
        $pageInformation = $this->dispatch(
            pageRecord: ['uid' => 889, 'no_index' => 1],
            routeArguments: ['tx_contentblockscollection_person' => ['person' => '  ']],
        );

        self::assertSame(1, $pageInformation->getPageRecord()['no_index']);
    }

    #[Test]
    public function testIndexablePageRecordIsLeftAsIs(): void
    {
        $pageRecord = ['uid' => 889, 'no_index' => 0];

        $pageInformation = $this->dispatch(
            pageRecord: $pageRecord,
            routeArguments: ['tx_contentblockscollection_person' => ['person' => 'kai-ole-hartwig']],
        );

        self::assertSame($pageRecord, $pageInformation->getPageRecord());
    }

    #[Test]
    public function testMissingRoutingAttributeIsIgnored(): void
    {
        $pageInformation = new PageInformation();
        $pageInformation->setPageRecord(['uid' => 889, 'no_index' => 1]);
        $event = new AfterPageAndLanguageIsResolvedEvent(new ServerRequest('https://example.org/'), $pageInformation);

        (new PluginDetailIndexingListener())($event);

        self::assertSame(1, $event->getPageInformation()->getPageRecord()['no_index']);
    }

    /**
     * @param array<string, mixed> $pageRecord
     * @param array<string, mixed> $routeArguments
     */
    private function dispatch(array $pageRecord, array $routeArguments): PageInformation
    {
        $pageInformation = new PageInformation();
        $pageInformation->setPageRecord($pageRecord);
        $request = (new ServerRequest('https://example.org/'))->withAttribute(
            'routing',
            new PageArguments(pageId: (int) $pageRecord['uid'], pageType: '0', routeArguments: $routeArguments),
        );
        $event = new AfterPageAndLanguageIsResolvedEvent($request, $pageInformation);

        (new PluginDetailIndexingListener())($event);

        return $event->getPageInformation();
    }
}
