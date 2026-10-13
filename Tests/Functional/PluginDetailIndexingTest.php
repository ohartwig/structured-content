<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Frontend\Event\AfterPageAndLanguageIsResolvedEvent;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The listener only helps if the container registers it for the core event.
 * A unit test calls it directly and cannot see a missing registration, so
 * this test dispatches the event through the real event dispatcher.
 */
final class PluginDetailIndexingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/structured-content'];

    #[Test]
    public function coreEventDispatchClearsNoIndexOnPluginDetailRoutes(): void
    {
        $pageInformation = new PageInformation();
        $pageInformation->setPageRecord(['uid' => 889, 'no_index' => 1]);
        $request = (new ServerRequest('https://example.org/autor/kai-ole-hartwig'))->withAttribute(
            'routing',
            new PageArguments(
                pageId: 889,
                pageType: '0',
                routeArguments: ['tx_contentblockscollection_person' => ['person' => 'kai-ole-hartwig']],
            ),
        );

        $event = $this->get(EventDispatcherInterface::class)
            ->dispatch(new AfterPageAndLanguageIsResolvedEvent($request, $pageInformation));

        self::assertSame(0, $event->getPageInformation()->getPageRecord()['no_index']);
    }
}
