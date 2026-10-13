<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * Port for emitting domain events.
 *
 * Structurally identical to PSR-14 — deliberately, so the adapter is a
 * one-line delegation. It exists as its own type because the Application layer
 * may not reach into the framework, and "PSR-14 is what the framework happens
 * to provide" is exactly the coupling the layering keeps out.
 */
interface EventDispatcherInterface
{
    /**
     * @template T of object
     *
     * @param T $event
     *
     * @return T the event, after listeners have had a chance to mutate it
     */
    public function dispatch(object $event): object;
}
