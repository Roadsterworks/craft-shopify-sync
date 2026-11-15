<?php

namespace roadsterworks\craftshopifysync\enums;

use roadsterworks\craftshopifysync\CraftShopifySync;

/**
 * Shopify product attributes and images that a Craft field can be mapped to.
 */
enum ShopifyAttribute: string
{
    case Title = 'title';
    case Handle = 'handle';
    case DescriptionHtml = 'descriptionHtml';
    case Vendor = 'vendor';
    case ProductType = 'productType';
    case Tags = 'tags';
    case SeoTitle = 'seoTitle';
    case SeoDescription = 'seoDescription';
    case FeaturedImage = 'featuredImage';
    case Images = 'images';

    public function label(): string
    {
        return match ($this) {
            self::Title => CraftShopifySync::t('Title'),
            self::Handle => CraftShopifySync::t('Handle'),
            self::DescriptionHtml => CraftShopifySync::t('Description (HTML)'),
            self::Vendor => CraftShopifySync::t('Vendor'),
            self::ProductType => CraftShopifySync::t('Product type'),
            self::Tags => CraftShopifySync::t('Tags'),
            self::SeoTitle => CraftShopifySync::t('SEO title'),
            self::SeoDescription => CraftShopifySync::t('SEO description'),
            self::FeaturedImage => CraftShopifySync::t('Featured image'),
            self::Images => CraftShopifySync::t('Images'),
        };
    }

    /**
     * Location of the attribute within Shopify's ProductCreateInput / ProductUpdateInput.
     *
     * @return string[]
     */
    public function inputPath(): array
    {
        return match ($this) {
            self::SeoTitle => ['seo', 'title'],
            self::SeoDescription => ['seo', 'description'],
            default => [$this->value],
        };
    }

    /**
     * Whether the destination is product media, set from an Assets field rather than sent as a product attribute.
     */
    public function isMedia(): bool
    {
        return $this === self::FeaturedImage || $this === self::Images;
    }

    /**
     * Whether Shopify expects a list of strings rather than a single string.
     */
    public function isList(): bool
    {
        return $this === self::Tags;
    }

    /**
     * Whether an empty value should be ignored rather than clearing the attribute in Shopify.
     *
     * Shopify requires a title, and an empty handle would be regenerated, so these fall back to the
     * entry's own title and slug instead.
     */
    public function keepsDefaultWhenEmpty(): bool
    {
        return $this === self::Title || $this === self::Handle;
    }
}
