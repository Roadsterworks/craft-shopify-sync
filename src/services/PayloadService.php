<?php

namespace roadsterworks\craftshopifysync\services;

use Craft;
use craft\base\EagerLoadingFieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use Illuminate\Support\Collection;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\ShopifyAttribute;
use roadsterworks\craftshopifysync\helpers\FieldValueSerializer;
use roadsterworks\craftshopifysync\models\FieldMapping;
use roadsterworks\craftshopifysync\models\ProductPayload;
use yii\base\Component;

/**
 * Builds an entry's Shopify payload from the field mappings.
 *
 * The entry title and slug are used for the Shopify title and handle unless other fields are mapped to them.
 */
class PayloadService extends Component
{
    /** Native entry attributes that can be mapped alongside custom fields. */
    public const NATIVE_FIELDS = ['title', 'slug'];

    public function build(Entry $entry): ProductPayload
    {
        $attributes = ['title' => (string)$entry->title, 'handle' => (string)$entry->slug];
        $metafields = $cleared = [];
        $featured = $images = null;

        foreach (CraftShopifySync::getInstance()->getSettings()->getFieldMappings($entry->getType()->handle) as $mapping) {
            $value = $this->fieldValue($entry, $mapping);
            $attribute = $mapping->getShopifyAttribute();

            if ($attribute === ShopifyAttribute::FeaturedImage) {
                $featured = array_slice($this->images($value), 0, 1);
                continue;
            }

            if ($attribute === ShopifyAttribute::Images) {
                $images = $this->images($value);
                continue;
            }

            if (!$mapping->isMetafield()) {
                $this->applyAttribute($attributes, $mapping, $value);
                continue;
            }

            $identifier = ['namespace' => (string)$mapping->namespace, 'key' => (string)$mapping->key];
            $serialised = FieldValueSerializer::toMetafieldValue($value, (string)$mapping->type);

            if ($serialised === null) {
                $cleared[] = $identifier;
            } else {
                $metafields[] = [...$identifier, 'type' => (string)$mapping->type, 'value' => $serialised];
            }
        }

        // Featured image first, then the rest; Shopify features the first image.
        $media = $featured === null && $images === null
            ? null
            : array_values(array_column([...($featured ?? []), ...($images ?? [])], null, 'originalSource'));

        return new ProductPayload($attributes, $metafields, $cleared, $media);
    }

    /**
     * A payload as readable rows, so editors can check what will be sent before syncing.
     *
     * @return array<int, array{label: string, value: string|null}> A null value means the metafield is removed.
     */
    public function previewRows(ProductPayload $payload): array
    {
        $rows = [];

        foreach (ShopifyAttribute::cases() as $attribute) {
            [$key, $nestedKey] = [...$attribute->inputPath(), null];
            $value = $nestedKey === null ? ($payload->productInput[$key] ?? null) : ($payload->productInput[$key][$nestedKey] ?? null);

            if ($value !== null) {
                $rows[] = ['label' => $attribute->label(), 'value' => is_array($value) ? implode(', ', $value) : (string)$value];
            }
        }

        foreach ($payload->media ?? [] as $i => $image) {
            $rows[] = ['label' => $i === 0 ? ShopifyAttribute::FeaturedImage->label() : CraftShopifySync::t('Image {n}', ['n' => $i + 1]), 'value' => $image['originalSource']];
        }

        if ($payload->media === []) {
            $rows[] = ['label' => ShopifyAttribute::Images->label(), 'value' => null];
        }

        foreach ($payload->metafields as $metafield) {
            $rows[] = ['label' => "{$metafield['namespace']}.{$metafield['key']}", 'value' => $metafield['value']];
        }

        foreach ($payload->clearedMetafields as $metafield) {
            $rows[] = ['label' => "{$metafield['namespace']}.{$metafield['key']}", 'value' => null];
        }

        return $rows;
    }

    /**
     * Handles of mapped relation fields, to eager-load when syncing many entries at once.
     *
     * @return string[]
     */
    public function eagerLoadableFields(): array
    {
        $handles = [];

        foreach (CraftShopifySync::getInstance()->getSettings()->fieldMappings as $mappings) {
            foreach (array_keys($mappings) as $handle) {
                if (Craft::$app->getFields()->getFieldByHandle((string)$handle) instanceof EagerLoadingFieldInterface) {
                    $handles[$handle] = (string)$handle;
                }
            }
        }

        return array_values($handles);
    }

    /**
     * Images from an Assets field value, as Shopify media. Shopify downloads them, so they need public URLs.
     *
     * @return array<int, array{originalSource: string, alt: string, mediaContentType: string}>
     */
    private function images(mixed $value): array
    {
        $assets = match (true) {
            $value instanceof ElementQueryInterface => $value->all(),
            $value instanceof Collection => $value->all(),
            default => [],
        };
        $images = [];

        foreach ($assets as $asset) {
            if (!$asset instanceof Asset || $asset->kind !== Asset::KIND_IMAGE) {
                continue;
            }

            $url = $asset->getUrl();
            if ($url === null || !str_starts_with($url, 'http')) {
                CraftShopifySync::getInstance()->logService->warning('Image skipped: it has no public URL', ['assetId' => $asset->id]);
                continue;
            }

            $images[] = ['originalSource' => $url, 'alt' => (string)($asset->alt ?: $asset->title), 'mediaContentType' => 'IMAGE'];
        }

        return $images;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function applyAttribute(array &$attributes, FieldMapping $mapping, mixed $value): void
    {
        $attribute = $mapping->getShopifyAttribute();
        if ($attribute === null) {
            return;
        }

        $serialised = $attribute->isList() ? FieldValueSerializer::toList($value) : FieldValueSerializer::toText($value);

        if (($serialised === null || $serialised === []) && $attribute->keepsDefaultWhenEmpty()) {
            return;
        }

        [$key, $nestedKey] = [...$attribute->inputPath(), null];

        if ($nestedKey === null) {
            $attributes[$key] = $serialised ?? '';
        } else {
            $attributes[$key][$nestedKey] = $serialised ?? '';
        }
    }

    private function fieldValue(Entry $entry, FieldMapping $mapping): mixed
    {
        if (in_array($mapping->craftField, self::NATIVE_FIELDS, true)) {
            return $entry->{$mapping->craftField};
        }

        // The field may have been removed from the entry type since it was mapped.
        if ($entry->getFieldLayout()?->getFieldByHandle($mapping->craftField) === null) {
            CraftShopifySync::getInstance()->logService->warning('Mapped field missing from entry type; skipped', [
                'entryId' => $entry->id,
                'field' => $mapping->craftField,
            ]);

            return null;
        }

        return $entry->getFieldValue($mapping->craftField);
    }
}
