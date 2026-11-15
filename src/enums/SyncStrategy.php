<?php

namespace roadsterworks\craftshopifysync\enums;

use roadsterworks\craftshopifysync\CraftShopifySync;

/**
 * What happens in Shopify when a product entry is deleted in Craft.
 */
enum SyncStrategy: string
{
    use HasOptions;

    case Unpublish = 'unpublish';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Unpublish => CraftShopifySync::t('Unpublish (set to draft)'),
            self::Delete => CraftShopifySync::t('Delete permanently'),
        };
    }
}
