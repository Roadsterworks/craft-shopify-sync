<?php

namespace roadsterworks\craftshopifysync\jobs;

use craft\queue\BaseJob;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\records\Product as ProductRecord;
use Throwable;
use yii\queue\RetryableJobInterface;

/**
 * Deletes every product in the Shopify store, linked to Craft or not, and the records of linked ones.
 *
 * IDs are fetched 250 at a time without a cursor (deleted products drop out of the next page) and deleted
 * 25 per request using aliased mutations.
 */
class DeleteAllShopifyProductsJob extends BaseJob implements RetryableJobInterface
{
    private const CHUNK_SIZE = 25;

    /** Stop after this many passes that delete nothing, to avoid looping forever. */
    private const MAX_EMPTY_PASSES = 3;

    public function execute($queue): void
    {
        $plugin = CraftShopifySync::getInstance();
        $shopify = $plugin->shopifyService;

        $total = $shopify->countProducts();
        $deleted = $emptyPasses = 0;
        $errors = [];

        while ($emptyPasses < self::MAX_EMPTY_PASSES && ($ids = $shopify->fetchProductIds()) !== []) {
            $deletedBefore = $deleted;

            foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
                try {
                    $result = $shopify->deleteProducts($chunk);
                    $deleted += count($result['deleted']);
                    ProductRecord::deleteAll(['shopifyId' => $result['deleted']]);
                    array_push($errors, ...$result['errors']);
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }

                $this->setProgress($queue, $total > 0 ? min(1, $deleted / $total) : 0, CraftShopifySync::t('Deleted {deleted} of {total}', [
                    'deleted' => $deleted,
                    'total' => max($total, $deleted),
                ]));
            }

            $emptyPasses = $deleted === $deletedBefore ? $emptyPasses + 1 : 0;
        }

        $context = ['deleted' => $deleted, 'errorCount' => count($errors), 'errors' => array_slice($errors, 0, 50)];

        if ($errors === []) {
            $plugin->logService->info('Deleted all Shopify products', $context);
        } else {
            $plugin->logService->error('Deleted all Shopify products with errors', $context);
        }
    }

    protected function defaultDescription(): ?string
    {
        return CraftShopifySync::t('Deleting all Shopify products');
    }

    /**
     * About 4 products a second, so an hour covers roughly 15,000 products.
     */
    public function getTtr(): int
    {
        return 3600;
    }

    /**
     * Destructive: never retry automatically.
     *
     * @param int $attempt
     * @param Throwable|null $error
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }
}
