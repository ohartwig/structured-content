<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * A generator that describes the page itself (WebPage, Article/BlogPosting)
 * and yields to a generator that claims the page entity of the URL. See
 * ClaimsPageEntityInterface.
 */
interface PageEntityGeneratorInterface extends SchemaGeneratorInterface {}
