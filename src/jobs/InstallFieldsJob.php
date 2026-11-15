<?php

namespace roadsterworks\craftshopifysync\jobs;

use craft\queue\BaseJob;
use roadsterworks\craftshopifysync\CraftShopifySync;

/**
 * Moves the Shopify Product field to the product section's entry types.
 */
class InstallFieldsJob extends BaseJob
{
    public string $oldSectionHandle = '';

    public string $newSectionHandle = '';

    public function execute($queue): void
    {
        CraftShopifySync::getInstance()->fieldLayoutService->install($this->newSectionHandle, $this->oldSectionHandle);
    }

    protected function defaultDescription(): ?string
    {
        return CraftShopifySync::t('Adding the Shopify field to “{section}”', ['section' => $this->newSectionHandle]);
    }
}
