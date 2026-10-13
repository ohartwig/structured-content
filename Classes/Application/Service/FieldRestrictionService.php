<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

use Koh\StructuredContent\Domain\Contract\ContextConfigurationProviderInterface;
use Koh\StructuredContent\Domain\Model\FilteredRecord;

final readonly class FieldRestrictionService
{
    public function __construct(
        private ContextConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Filters a record based on field restrictions for a given channel.
     *
     * @param array<string, mixed> $record
     */
    public function filterRecord(string $table, array $record, string $channel): FilteredRecord
    {
        $restrictions = $this->configurationProvider->getFieldRestrictionsForTable(
            table: $table,
            channel: $channel,
        );

        if (null === $restrictions || [] === $restrictions) {
            return new FilteredRecord(
                record: $record,
                removedFields: [],
                reason: null,
            );
        }

        $filteredRecord = $record;
        $removedFields = [];

        foreach ($restrictions as $field) {
            if (array_key_exists($field, $filteredRecord)) {
                unset($filteredRecord[$field]);
                $removedFields[] = $field;
            }
        }

        return new FilteredRecord(
            record: $filteredRecord,
            removedFields: $removedFields,
            reason: [] !== $removedFields ? sprintf('Channel restriction for %s', $channel) : null,
        );
    }
}
