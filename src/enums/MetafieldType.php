<?php

namespace roadsterworks\craftshopifysync\enums;

use roadsterworks\craftshopifysync\CraftShopifySync;

/**
 * Metafield types offered for custom metafields. Metafields with a Shopify definition use the definition's type.
 *
 * @see https://shopify.dev/docs/apps/build/custom-data/metafields/list-of-data-types
 */
enum MetafieldType: string
{
    use HasOptions;

    case SingleLineText = 'single_line_text_field';
    case MultiLineText = 'multi_line_text_field';
    case RichText = 'rich_text_field';
    case Integer = 'number_integer';
    case Decimal = 'number_decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'date_time';
    case Url = 'url';
    case Colour = 'color';
    case Json = 'json';
    case ProductReference = 'product_reference';
    case SingleLineTextList = 'list.single_line_text_field';
    case ProductReferenceList = 'list.product_reference';

    public function label(): string
    {
        return match ($this) {
            self::SingleLineText => CraftShopifySync::t('Single line text'),
            self::MultiLineText => CraftShopifySync::t('Multi-line text'),
            self::RichText => CraftShopifySync::t('Rich text'),
            self::Integer => CraftShopifySync::t('Integer'),
            self::Decimal => CraftShopifySync::t('Decimal'),
            self::Boolean => CraftShopifySync::t('True or false'),
            self::Date => CraftShopifySync::t('Date'),
            self::DateTime => CraftShopifySync::t('Date and time'),
            self::Url => CraftShopifySync::t('URL'),
            self::Colour => CraftShopifySync::t('Colour'),
            self::Json => CraftShopifySync::t('JSON'),
            self::ProductReference => CraftShopifySync::t('Product (synced Craft entry)'),
            self::SingleLineTextList => CraftShopifySync::t('List of single line text'),
            self::ProductReferenceList => CraftShopifySync::t('List of products (synced Craft entries)'),
        };
    }
}
