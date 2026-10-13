<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Adapter;

use Koh\StructuredContent\Domain\Contract\EventDispatcherInterface;
use Psr\EventDispatcher\EventDispatcherInterface as PsrEventDispatcherInterface;

/**
 * Binds the domain's event port to whatever PSR-14 dispatcher the framework
 * wires up.
 */
final readonly class PsrEventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private PsrEventDispatcherInterface $dispatcher,
    ) {}

    /**
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     */
    public function dispatch(object $event): object
    {
        /** @var T $dispatched */
        $dispatched = $this->dispatcher->dispatch($event);

        return $dispatched;
    }
}
