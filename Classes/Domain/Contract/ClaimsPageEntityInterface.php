<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * A generator whose document is the page entity of the URL being rendered
 * when it produces one — a ProfilePage on an author detail URL.
 *
 * One URL has one page entity. When a generator of this kind returns a
 * document with data, SchemaOrgService drops the documents of every
 * PageEntityGeneratorInterface generator (the generic WebPage and the
 * Article/BlogPosting of the container page), which would otherwise describe
 * the same URL a second and a third time with contradicting data.
 */
interface ClaimsPageEntityInterface extends SchemaGeneratorInterface {}
