<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

/**
 * Was in einem Schema-Dokument als "nicht gesetzt" gilt.
 *
 * Die Generatoren bauen ihr Dokument als Array auf und werfen am Ende die
 * leeren Eintraege weg. Mit `array_filter($data)` — also ohne Callback — faellt
 * dabei aber jeder falsy Wert heraus, und dazu gehoeren `0` und `false`.
 *
 * In Schema.org sind das gueltige Werte: ein Preis von 0, eine Bewertung von 0,
 * `position: 0` in einer ItemList, `isAccessibleForFree: false`. Sie
 * verschwinden dann still aus dem ausgelieferten JSON-LD, und weder ein Test
 * noch ein Validator meldet etwas — das Feld ist schlicht nicht da.
 *
 * Deshalb dieser Callback statt der Kurzform. Er entfernt genau das, was
 * gemeint ist: nie gesetzt, leerer String, leeres Array.
 */
final class SchemaValues
{
    public static function present(mixed $value): bool
    {
        return null !== $value && '' !== $value && [] !== $value;
    }
}
