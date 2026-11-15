<?php

namespace roadsterworks\craftshopifysync\helpers;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use roadsterworks\craftshopifysync\records\Product as ProductRecord;
use Stringable;
use Throwable;

/**
 * Converts Craft field values into the strings Shopify expects for product attributes and metafields.
 *
 * Relations become element titles (or asset URLs); product reference metafields become Shopify product IDs.
 */
class FieldValueSerializer
{
    private const LIST_PREFIX = 'list.';
    private const PRODUCT_REFERENCE = 'product_reference';

    /**
     * Serialise a value to a single string, or null when empty. Multiple values are comma-separated.
     */
    public static function toText(mixed $value): ?string
    {
        $value = self::normalise($value);
        $text = is_array($value) ? implode(', ', self::toStrings($value)) : self::scalarToString($value);

        return $text === '' ? null : $text;
    }

    /**
     * Serialise a value to a list of strings. Comma-separated text is split into separate items.
     *
     * @return string[]
     */
    public static function toList(mixed $value): array
    {
        $value = self::normalise($value);
        $items = is_array($value) ? self::toStrings($value) : explode(',', self::scalarToString($value));

        return array_values(array_filter(array_map('trim', $items), static fn(string $item): bool => $item !== ''));
    }

    /**
     * Serialise a value for a Shopify metafield of the given type, or null when empty.
     *
     * @see https://shopify.dev/docs/apps/build/custom-data/metafields/list-of-data-types
     */
    public static function toMetafieldValue(mixed $value, string $type): ?string
    {
        $isList = str_starts_with($type, self::LIST_PREFIX);
        $baseType = $isList ? substr($type, strlen(self::LIST_PREFIX)) : $type;

        if ($baseType === self::PRODUCT_REFERENCE) {
            $productIds = self::productIds($value);

            return $isList ? self::encodeList($productIds) : ($productIds[0] ?? null);
        }

        $value = self::normalise($value);

        if ($isList) {
            $items = array_map(
                static fn(mixed $item): ?string => self::formatScalar($item, $baseType),
                is_array($value) ? self::flatten($value) : [$value],
            );
            $items = array_filter($items, static fn(?string $item): bool => $item !== null);

            return self::encodeList(match ($baseType) {
                'number_integer' => array_map('intval', $items),
                'number_decimal' => array_map('floatval', $items),
                default => $items,
            });
        }

        if ($baseType === 'json') {
            return self::isEmpty($value) ? null : self::json($value);
        }

        if (is_array($value)) {
            $value = implode(', ', self::toStrings($value));
        }

        return self::formatScalar($value, $baseType);
    }

    /**
     * Reduce Craft field values to scalars, DateTimes and (nested) arrays of those.
     */
    private static function normalise(mixed $value): mixed
    {
        return match (true) {
            $value instanceof ElementQueryInterface => array_map(self::normalise(...), $value->all()),
            $value instanceof Collection => array_map(self::normalise(...), $value->all()),
            // Option fields send their labels, which is what shoppers should see.
            $value instanceof MultiOptionsFieldData => array_map(
                static fn(OptionData $option): ?string => $option->label,
                $value->getArrayCopy(),
            ),
            $value instanceof OptionData => ($value->value ?? '') === '' ? null : $value->label,
            $value instanceof Asset => $value->getUrl(),
            $value instanceof ElementInterface => (string)$value,
            $value instanceof DateTimeInterface => $value,
            $value instanceof Stringable => (string)$value,
            is_array($value) => array_map(self::normalise(...), $value),
            default => $value,
        };
    }

    private static function formatScalar(mixed $value, string $type): ?string
    {
        if (self::isEmpty($value)) {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            'number_integer' => is_numeric($value) ? (string)(int)$value : null,
            'number_decimal' => is_numeric($value) ? (string)(float)$value : null,
            'date' => self::toDateTime($value)?->format('Y-m-d'),
            'date_time' => self::toDateTime($value)?->format(DATE_ATOM),
            'rich_text_field' => self::toRichText(self::scalarToString($value)),
            default => self::scalarToString($value),
        };
    }

    /**
     * Convert HTML or plain text into Shopify's rich text JSON document, one paragraph per block.
     */
    private static function toRichText(string $html): ?string
    {
        $text = strip_tags((string)preg_replace('#</p>|<br\s*/?>#i', "\n\n", $html));
        $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', html_entity_decode($text)) ?: []));

        if ($paragraphs === []) {
            return null;
        }

        return self::json([
            'type' => 'root',
            'children' => array_map(static fn(string $paragraph): array => [
                'type' => 'paragraph',
                'children' => [['type' => 'text', 'value' => $paragraph]],
            ], array_values($paragraphs)),
        ]);
    }

    /**
     * Resolve related Craft entries to the Shopify product IDs they are synced to, preserving order.
     *
     * @return string[]
     */
    private static function productIds(mixed $value): array
    {
        $entryIds = match (true) {
            $value instanceof ElementQueryInterface => $value->ids(),
            $value instanceof Collection => $value->map(static fn(ElementInterface $element) => $element->id)->all(),
            $value instanceof ElementInterface => [$value->id],
            is_array($value) => array_filter($value, 'is_numeric'),
            default => [],
        };

        if ($entryIds === []) {
            return [];
        }

        $productIds = ProductRecord::find()
            ->select(['shopifyId'])
            ->where(['entryId' => $entryIds])
            ->andWhere(['not', ['shopifyId' => null]])
            ->indexBy('entryId')
            ->column();

        return array_values(array_filter(array_map(
            static fn(int|string $entryId): ?string => $productIds[$entryId] ?? null,
            $entryIds,
        )));
    }

    private static function toDateTime(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        try {
            return new DateTimeImmutable((string)$value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function scalarToString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_scalar($value) => (string)$value,
            default => self::json($value),
        };
    }

    /**
     * @param array<mixed> $values
     * @return string[]
     */
    private static function toStrings(array $values): array
    {
        return array_values(array_filter(
            array_map(self::scalarToString(...), self::flatten($values)),
            static fn(string $item): bool => $item !== '',
        ));
    }

    /**
     * @param array<mixed> $values
     * @return array<int, mixed>
     */
    private static function flatten(array $values): array
    {
        $flat = [];
        array_walk_recursive($values, static function(mixed $item) use (&$flat): void {
            $flat[] = $item;
        });

        return $flat;
    }

    /**
     * @param array<mixed> $items
     */
    private static function encodeList(array $items): ?string
    {
        return $items === [] ? null : self::json(array_values($items));
    }

    private static function json(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_array($value) && self::toStrings($value) === []);
    }
}
