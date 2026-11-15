<?php

namespace roadsterworks\craftshopifysync\elements\actions;

use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use roadsterworks\craftshopifysync\CraftShopifySync;

/**
 * Entry index action that queues a sync for the selected product entries.
 */
class SyncToShopify extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return CraftShopifySync::t('Sync to Shopify');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $syncService = CraftShopifySync::getInstance()->syncService;
        $count = 0;

        foreach ($query->all() as $entry) {
            if ($entry instanceof Entry && $syncService->isProductEntry($entry)) {
                $syncService->queueEntrySync($entry, true);
                $count++;
            }
        }

        $this->setMessage(CraftShopifySync::t('{count, plural, =1{# product} other{# products}} queued to sync.', ['count' => $count]));

        return true;
    }
}
