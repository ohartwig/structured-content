<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * A schema generator whose content-block mappings are configured per site.
 *
 * Takes the resolved mapping array rather than a Site: the only consumer is a
 * Presentation middleware, which may not depend on Infrastructure, so the
 * contract has to live in Domain — and Domain depends on nothing, least of all
 * on a framework's Site entity. Reading the setting stays with the caller,
 * which holds the site anyway.
 */
interface SchemaMappingConfigurableInterface
{
    /**
     * @param array<string, array{schemaType: string, fields: array<string, string>}> $mappings
     */
    public function setMappings(array $mappings): void;
}
