<?php

namespace roadsterworks\craftshopifysync\enums;

use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\Template;
use roadsterworks\craftshopifysync\CraftShopifySync;
use Twig\Markup;

/**
 * Sync state of an entry's product record.
 */
enum SyncStatus: string
{
    case Synced = 'synced';
    case Failed = 'failed';
    case Unpublished = 'unpublished';

    public function label(): string
    {
        return match ($this) {
            self::Synced => CraftShopifySync::t('Synced'),
            self::Failed => CraftShopifySync::t('Failed'),
            self::Unpublished => CraftShopifySync::t('Unpublished'),
        };
    }

    public function colour(): Color
    {
        return match ($this) {
            self::Synced => Color::Green,
            self::Failed => Color::Red,
            self::Unpublished => Color::Orange,
        };
    }

    /**
     * Craft status label, with “Not synced” for entries that have no record yet.
     */
    public static function labelHtml(?self $status): Markup
    {
        return Template::raw((string)Cp::statusLabelHtml([
            'color' => $status?->colour() ?? Color::Gray,
            'label' => $status?->label() ?? CraftShopifySync::t('Not synced'),
        ]));
    }
}
