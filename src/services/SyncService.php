<?php

namespace roadsterworks\craftshopifysync\services;

use Craft;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\CreationStrategy;
use roadsterworks\craftshopifysync\enums\SyncStatus;
use roadsterworks\craftshopifysync\enums\SyncStrategy;
use roadsterworks\craftshopifysync\errors\ShopifyApiException;
use roadsterworks\craftshopifysync\jobs\DeleteAllShopifyProductsJob;
use roadsterworks\craftshopifysync\jobs\InstallFieldsJob;
use roadsterworks\craftshopifysync\jobs\RemoveProductJob;
use roadsterworks\craftshopifysync\jobs\SyncAllProductsJob;
use roadsterworks\craftshopifysync\jobs\SyncProductJob;
use roadsterworks\craftshopifysync\records\Product as ProductRecord;
use Throwable;
use yii\base\Component;

/**
 * Syncs product entries to Shopify.
 *
 * `queue*()` and `handle*()` methods only push queue jobs; `syncEntry()` and `removeProduct()` do the work.
 */
class SyncService extends Component
{
    private const STATUS_ACTIVE = 'ACTIVE';
    private const STATUS_DRAFT = 'DRAFT';

    /** Run single-entry jobs ahead of bulk syncs (Craft's default priority is 1024; lower runs first). */
    private const ENTRY_PRIORITY = 100;

    /** @var array<int, string> Shopify IDs captured before entries are deleted, keyed by entry ID. */
    private array $pendingRemovals = [];

    // Queueing
    // -------------------------------------------------------------------------

    /**
     * Duplicate jobs are harmless: unchanged products are skipped by their content hash.
     */
    public function queueEntrySync(Entry $entry, bool $force = false): void
    {
        $this->push(new SyncProductJob([
            'entryId' => (int)$entry->getCanonicalId(),
            'force' => $force,
            'description' => CraftShopifySync::t('Syncing “{title}” to Shopify', ['title' => $entry->title]),
        ]), self::ENTRY_PRIORITY);
    }

    /**
     * @param bool $force Re-send products even when their content hasn't changed.
     */
    public function queueFullSync(bool $force = false): ?string
    {
        return $this->push(new SyncAllProductsJob(['force' => $force]));
    }

    public function queueDeleteAll(): ?string
    {
        return $this->push(new DeleteAllShopifyProductsJob());
    }

    public function queueFieldInstall(string $oldSectionHandle, string $newSectionHandle): ?string
    {
        return $this->push(new InstallFieldsJob(['oldSectionHandle' => $oldSectionHandle, 'newSectionHandle' => $newSectionHandle]));
    }

    // Entry events
    // -------------------------------------------------------------------------

    public function handleEntrySaved(Entry $entry): void
    {
        if (
            $entry->propagating ||
            $entry->resaving ||
            ElementHelper::isDraftOrRevision($entry) ||
            !$this->plugin()->getSettings()->syncOnSave ||
            !$this->isProductEntry($entry)
        ) {
            return;
        }

        $this->queueEntrySync($entry);
    }

    /**
     * Capture the Shopify ID first: a hard delete cascades to the product record.
     */
    public function handleEntryDeleting(Entry $entry): void
    {
        if (ElementHelper::isDraftOrRevision($entry) || !$this->isProductEntry($entry)) {
            return;
        }

        $shopifyId = ProductRecord::find()->select(['shopifyId'])->where(['entryId' => $entry->id])->scalar();

        if ($shopifyId) {
            $this->pendingRemovals[$entry->id] = (string)$shopifyId;
        }
    }

    public function handleEntryDeleted(Entry $entry): void
    {
        $shopifyId = $this->pendingRemovals[$entry->id] ?? null;
        unset($this->pendingRemovals[$entry->id]);

        if ($shopifyId === null) {
            return;
        }

        $strategy = $this->plugin()->getSettings()->getSyncStrategy();

        $this->push(new RemoveProductJob([
            'shopifyId' => $shopifyId,
            'strategy' => $strategy->value,
            'description' => CraftShopifySync::t(
                $strategy === SyncStrategy::Delete ? 'Deleting “{title}” from Shopify' : 'Unpublishing “{title}” in Shopify',
                ['title' => $entry->title],
            ),
        ]));
    }

    public function handleEntryRestored(Entry $entry): void
    {
        if ($this->isProductEntry($entry)) {
            $this->queueEntrySync($entry);
        }
    }

    // Sync operations (run from queue jobs)
    // -------------------------------------------------------------------------

    /**
     * Push an entry to Shopify, creating the product if it isn't linked yet.
     *
     * @param bool $force Send the product even if it's unchanged since the last sync.
     * @return bool Whether Shopify was updated (false when skipped as unchanged).
     * @throws Throwable When Shopify rejects the product. The failure is recorded first.
     */
    public function syncEntry(Entry $entry, bool $force = false): bool
    {
        $plugin = $this->plugin();
        $record = ProductRecord::findOrNew((int)$entry->id);
        $payload = $plugin->payloadService->build($entry);
        $hash = $payload->hash();

        if (!$force && $record->isLinked() && $record->getSyncStatus() === SyncStatus::Synced && $record->contentHash === $hash) {
            return false;
        }

        $shopify = $plugin->shopifyService;
        $status = ['status' => $this->initialStatus($entry)];
        // Only cleared by a successful sync, so a failed restore still resets the status on retry.
        $isRestore = $record->unpublishedAt !== null;

        // Images are only replaced when they've changed in Craft, as Shopify re-downloads every one.
        $mediaChanged = $payload->media !== null && $payload->mediaHash() !== $record->mediaHash;

        try {
            $product = null;

            if ($record->isLinked()) {
                if ($mediaChanged) {
                    $shopify->clearProductMedia((string)$record->shopifyId);
                }

                $product = $shopify->updateProduct(
                    $payload->toInput(['id' => $record->shopifyId, ...($isRestore ? $status : [])]),
                    $mediaChanged ? $payload->media : null,
                );
            }

            if ($product === null) {
                if ($record->isLinked()) {
                    $plugin->logService->warning('Linked product missing in Shopify; recreating', ['entryId' => $entry->id, 'shopifyId' => $record->shopifyId]);
                }

                $product = $shopify->createProduct($payload->toInput($status), $payload->media);
            }

            $shopify->deleteMetafields($product['id'], $payload->clearedMetafields);
        } catch (Throwable $e) {
            $this->recordFailure($record, $e, ['entryId' => $entry->id]);

            throw $e;
        }

        $record->setAttributes([
            'shopifyId' => $product['id'],
            'shopifyHandle' => $product['handle'],
            'status' => SyncStatus::Synced->value,
            'contentHash' => $hash,
            'mediaHash' => $payload->mediaHash(),
            'lastError' => null,
            'lastSyncedAt' => DateTimeHelper::currentUTCDateTime(),
            'unpublishedAt' => null,
        ], false);
        $record->save(false);

        $plugin->logService->info('Product synced', ['entryId' => $entry->id, 'shopifyId' => $product['id']]);

        return true;
    }

    /**
     * Unpublish or delete a product after its entry was deleted.
     *
     * @throws Throwable
     */
    public function removeProduct(string $shopifyId, SyncStrategy $strategy): void
    {
        $shopify = $this->plugin()->shopifyService;
        $record = ProductRecord::findOne(['shopifyId' => $shopifyId]);

        try {
            if ($strategy === SyncStrategy::Delete) {
                $errors = $shopify->deleteProducts([$shopifyId])['errors'];
                if ($errors !== []) {
                    throw new ShopifyApiException('Shopify rejected productDelete', $errors);
                }

                $record?->delete();
            } else {
                $shopify->updateProduct(['id' => $shopifyId, 'status' => self::STATUS_DRAFT]);
                $record?->setAttributes([
                    'status' => SyncStatus::Unpublished->value,
                    'unpublishedAt' => DateTimeHelper::currentUTCDateTime(),
                    'lastError' => null,
                ], false);
                $record?->save(false);
            }
        } catch (Throwable $e) {
            $this->recordFailure($record, $e, ['shopifyId' => $shopifyId, 'strategy' => $strategy->value]);

            throw $e;
        }

        $this->plugin()->logService->info('Product removed', ['shopifyId' => $shopifyId, 'strategy' => $strategy->value]);
    }

    // Queries
    // -------------------------------------------------------------------------

    /**
     * Non-trashed entries in the product section, in the primary site where available.
     *
     * @return EntryQuery<int, Entry>
     */
    public function productEntryQuery(): EntryQuery
    {
        $sectionHandle = $this->plugin()->getSettings()->productSectionHandle;
        $query = Entry::find()
            ->status(null)
            ->site('*')
            ->unique()
            ->preferSites([Craft::$app->getSites()->getPrimarySite()->id])
            ->orderBy(['elements.id' => SORT_ASC]);

        // With no section configured, match nothing rather than every entry.
        return $sectionHandle !== '' ? $query->section($sectionHandle) : $query->andWhere('0 = 1');
    }

    public function findProductEntry(int $entryId): ?Entry
    {
        return $this->productEntryQuery()->id($entryId)->one();
    }

    public function isProductEntry(Entry $entry): bool
    {
        $sectionHandle = $this->plugin()->getSettings()->productSectionHandle;

        return $sectionHandle !== '' && $entry->getSection()?->handle === $sectionHandle;
    }

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Active only when the creation strategy is "live" and the entry is live.
     */
    private function initialStatus(Entry $entry): string
    {
        $live = $this->plugin()->getSettings()->getCreationStrategy() === CreationStrategy::Live
            && $entry->getStatus() === Entry::STATUS_LIVE;

        return $live ? self::STATUS_ACTIVE : self::STATUS_DRAFT;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordFailure(?ProductRecord $record, Throwable $e, array $context): void
    {
        if ($record !== null) {
            $record->status = SyncStatus::Failed->value;
            $record->lastError = mb_substr($e->getMessage(), 0, 2000);
            $record->save(false);
        }

        $this->plugin()->logService->error('Sync failed', [...$context, 'error' => $e->getMessage()]);
    }

    private function push(BaseJob $job, ?int $priority = null): ?string
    {
        $jobId = Queue::push($job, $priority);
        $this->plugin()->logService->info('Queued job', ['job' => $job::class, 'jobId' => $jobId]);

        return $jobId;
    }

    private function plugin(): CraftShopifySync
    {
        return CraftShopifySync::getInstance();
    }
}
