<?php

namespace roadsterworks\craftshopifysync\jobs;

use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\queue\BaseBatchedJob;
use roadsterworks\craftshopifysync\CraftShopifySync;
use Throwable;

/**
 * Syncs every product entry, one batch per queue job so large catalogues never hit the TTR.
 * A failing product is recorded and logged without stopping the batch.
 */
class SyncAllProductsJob extends BaseBatchedJob
{
    /** Re-send products even when they haven't changed. */
    public bool $force = false;

    public int $batchSize = 25;

    protected function loadData(): Batchable
    {
        $plugin = CraftShopifySync::getInstance();

        return new QueryBatcher($plugin->syncService->productEntryQuery()->with($plugin->payloadService->eagerLoadableFields()));
    }

    /**
     * @param Entry $item
     */
    protected function processItem(mixed $item): void
    {
        try {
            CraftShopifySync::getInstance()->syncService->syncEntry($item, $this->force);
        } catch (Throwable) {
            // Already recorded and logged by the sync service.
        }
    }

    protected function defaultDescription(): ?string
    {
        return $this->force
            ? CraftShopifySync::t('Re-syncing all products to Shopify')
            : CraftShopifySync::t('Syncing changed products to Shopify');
    }
}
