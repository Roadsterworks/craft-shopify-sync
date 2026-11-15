<?php

namespace roadsterworks\craftshopifysync\models;

use craft\base\Model;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\ShopifyAttribute;

/**
 * Maps one Craft field to a Shopify product attribute or metafield.
 *
 * The control panel's select encodes a mapping as `attribute:vendor`,
 * `metafield:custom.material:single_line_text_field`, or {@see self::CUSTOM_TARGET} for a hand-entered metafield.
 */
class FieldMapping extends Model
{
    public const CUSTOM_TARGET = 'custom';

    private const ATTRIBUTE_PREFIX = 'attribute:';
    private const METAFIELD_PREFIX = 'metafield:';
    private const METAFIELD_TARGET_PATTERN = '/^metafield:([^.:]+)\.([^.:]+):(.+)$/';

    /** Handle of the mapped Craft field (`title` and `slug` refer to the native entry attributes). */
    public string $craftField = '';

    /** @see ShopifyAttribute */
    public ?string $attribute = null;

    public ?string $namespace = null;

    public ?string $key = null;

    /** Shopify metafield type, e.g. `single_line_text_field` or `list.product_reference`. */
    public ?string $type = null;

    /**
     * Build a mapping from a posted select value.
     *
     * @param array{namespace?: string, key?: string, type?: string} $custom Values for a custom metafield.
     * @return self|null Null when the field is left unmapped.
     */
    public static function fromTarget(string $craftField, string $target, array $custom = []): ?self
    {
        $target = trim($target);

        if ($target === '') {
            return null;
        }

        if ($target === self::CUSTOM_TARGET) {
            return new self([
                'craftField' => $craftField,
                'namespace' => trim($custom['namespace'] ?? ''),
                'key' => trim($custom['key'] ?? ''),
                'type' => trim($custom['type'] ?? ''),
            ]);
        }

        if (str_starts_with($target, self::ATTRIBUTE_PREFIX)) {
            return new self([
                'craftField' => $craftField,
                'attribute' => substr($target, strlen(self::ATTRIBUTE_PREFIX)),
            ]);
        }

        if (preg_match(self::METAFIELD_TARGET_PATTERN, $target, $matches)) {
            return new self([
                'craftField' => $craftField,
                'namespace' => $matches[1],
                'key' => $matches[2],
                'type' => $matches[3],
            ]);
        }

        // Unrecognised value: keep it so validation can report it rather than silently dropping the mapping.
        return new self(['craftField' => $craftField, 'attribute' => $target]);
    }

    /**
     * Encode a core attribute as a select value.
     */
    public static function attributeTarget(ShopifyAttribute $attribute): string
    {
        return self::ATTRIBUTE_PREFIX . $attribute->value;
    }

    /**
     * Encode a metafield as a select value.
     */
    public static function metafieldTarget(string $namespace, string $key, string $type): string
    {
        return self::METAFIELD_PREFIX . "{$namespace}.{$key}:{$type}";
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['craftField'], 'required'],
            [['attribute'], 'in', 'range' => array_column(ShopifyAttribute::cases(), 'value'), 'when' => fn() => !$this->isMetafield()],
            [['namespace', 'key', 'type'], 'required', 'when' => fn() => $this->isMetafield()],
            [['namespace'], 'string', 'min' => 3, 'max' => 255],
            [['key'], 'string', 'min' => 2, 'max' => 64],
            [['namespace', 'key'], 'match', 'pattern' => '/^[a-zA-Z0-9_-]+$/', 'message' => '{attribute} may only contain letters, numbers, hyphens and underscores.'],
            [['type'], 'match', 'pattern' => '/^(list\.)?[a-z_]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'craftField' => CraftShopifySync::t('Craft field'),
            'attribute' => CraftShopifySync::t('Shopify attribute'),
            'namespace' => CraftShopifySync::t('Metafield namespace'),
            'key' => CraftShopifySync::t('Metafield key'),
            'type' => CraftShopifySync::t('Metafield type'),
        ];
    }

    public function isMetafield(): bool
    {
        return $this->attribute === null;
    }

    public function getShopifyAttribute(): ?ShopifyAttribute
    {
        return $this->attribute !== null ? ShopifyAttribute::tryFrom($this->attribute) : null;
    }

    /**
     * Identifies the Shopify destination, used to prevent two Craft fields writing to the same place.
     */
    public function getDestinationKey(): string
    {
        return $this->isMetafield()
            ? self::METAFIELD_PREFIX . "{$this->namespace}.{$this->key}"
            : self::ATTRIBUTE_PREFIX . $this->attribute;
    }

    /**
     * The select value representing this mapping.
     */
    public function getTargetValue(): string
    {
        return $this->isMetafield()
            ? self::metafieldTarget((string)$this->namespace, (string)$this->key, (string)$this->type)
            : self::ATTRIBUTE_PREFIX . $this->attribute;
    }

    /**
     * Human-readable destination, e.g. "Vendor" or "custom.material".
     */
    public function getDestinationLabel(): string
    {
        return $this->isMetafield()
            ? "{$this->namespace}.{$this->key}"
            : ($this->getShopifyAttribute()?->label() ?? (string)$this->attribute);
    }

    /**
     * Compact representation stored in project config.
     *
     * @return array<string, string>
     */
    public function toConfig(): array
    {
        return array_filter([
            'craftField' => $this->craftField,
            'attribute' => $this->attribute,
            'namespace' => $this->namespace,
            'key' => $this->key,
            'type' => $this->type,
        ], static fn(?string $value): bool => $value !== null && $value !== '');
    }
}
