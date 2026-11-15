<?php

namespace roadsterworks\craftshopifysync\jobs;

use craft\queue\BaseJob;
use roadsterworks\craftshopifysync\CraftShopifySync;
use yii\queue\RetryableJobInterface;

class SyncProductJob extends BaseJob implements RetryableJobInterface
{
    use RetriesOnFailure;

    public int $entryId;

    /** Send the product even if it's unchanged since the last sync. */
    public bool $force = false;

    public function execute($queue): void
    {
        $syncService = CraftShopifySync::getInstance()->syncService;

        if ($entry = $syncService->findProductEntry($this->entryId)) {
            $syncService->syncEntry($entry, $this->force);
        }
    }

    protected function defaultDescription(): ?string
    {
        return CraftShopifySync::t('Syncing product to Shopify');
    }
}
