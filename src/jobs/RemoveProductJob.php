<?php

namespace roadsterworks\craftshopifysync\jobs;

use craft\queue\BaseJob;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\SyncStrategy;
use yii\queue\RetryableJobInterface;

/**
 * Unpublishes or deletes a product after its entry was deleted.
 */
class RemoveProductJob extends BaseJob implements RetryableJobInterface
{
    use RetriesOnFailure;

    /** Captured when queued: a hard-deleted entry takes its product record with it. */
    public string $shopifyId;

    /** @see SyncStrategy */
    public string $strategy;

    public function execute($queue): void
    {
        CraftShopifySync::getInstance()->syncService->removeProduct($this->shopifyId, SyncStrategy::from($this->strategy));
    }

    protected function defaultDescription(): ?string
    {
        return CraftShopifySync::t('Removing product from Shopify');
    }
}
