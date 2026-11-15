<?php

namespace roadsterworks\craftshopifysync\enums;

use roadsterworks\craftshopifysync\CraftShopifySync;

/**
 * The Shopify status given to newly created (or restored) products.
 */
enum CreationStrategy: string
{
    use HasOptions;

    case Live = 'live';
    case Unpublished = 'unpublished';

    public function label(): string
    {
        return match ($this) {
            self::Live => CraftShopifySync::t('Live (active)'),
            self::Unpublished => CraftShopifySync::t('Unpublished (draft)'),
        };
    }
}
