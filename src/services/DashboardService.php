<?php

namespace roadsterworks\craftshopifysync\services;

use craft\elements\Entry;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\SyncStatus;
use roadsterworks\craftshopifysync\records\Product as ProductRecord;
use Throwable;
use yii\base\Component;

/**
 * Data for the plugin dashboard.
 */
class DashboardService extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $settings = CraftShopifySync::getInstance()->getSettings();

        /** @var ProductRecord[] $failed */
        $failed = ProductRecord::find()->where(['status' => SyncStatus::Failed->value])->orderBy(['dateUpdated' => SORT_DESC])->limit(50)->all();
        /** @var ProductRecord[] $recent */
        $recent = ProductRecord::find()->orderBy(['dateUpdated' => SORT_DESC])->limit(10)->all();
        $entries = $this->entriesFor([...$failed, ...$recent]);
        $rows = static fn(array $records): array => array_map(
            static fn(ProductRecord $record): array => ['record' => $record, 'entry' => $entries[$record->entryId] ?? null],
            $records,
        );

        return [
            'isReady' => $settings->isReady(),
            'storeDomain' => $settings->getStoreDomain(),
            'stats' => $this->getStats(),
            'failed' => $rows($failed),
            'recentActivity' => $rows($recent),
        ];
    }

    /**
     * Stats fetched after the page loads, so Shopify never slows it down.
     *
     * @param bool $withShopify Also ask Shopify for its product count. Skipped while polling during a sync, to leave
     *     the API budget to the sync itself.
     * @return array{stats: array<string, int|null>, shopifyError?: string|null}
     */
    public function getLiveStats(bool $withShopify = true): array
    {
        $stats = $this->getStats();
        unset($stats['shopifyProducts']);

        if (!$withShopify) {
            return ['stats' => $stats];
        }

        [$stats['shopifyProducts'], $shopifyError] = $this->shopifyProductCount();

        return ['stats' => $stats, 'shopifyError' => $shopifyError];
    }

    /**
     * Counts for the stat cards. Synced, failed and not-synced only cover entries currently in the product
     * section; unpublished covers deleted entries whose products were set to draft.
     *
     * @return array<string, int|null>
     */
    private function getStats(): array
    {
        $entryQuery = CraftShopifySync::getInstance()->syncService->productEntryQuery();
        $craftProducts = (int)$entryQuery->count();

        /** @var array<string, string> $counts */
        $counts = ProductRecord::find()
            ->select(['total' => 'COUNT(*)'])
            ->where(['entryId' => (clone $entryQuery)->select(['elements.id'])->orderBy([])])
            ->groupBy(['status'])
            ->indexBy('status')
            ->column();

        return [
            'craftProducts' => $craftProducts,
            'synced' => (int)($counts[SyncStatus::Synced->value] ?? 0),
            'failed' => (int)($counts[SyncStatus::Failed->value] ?? 0),
            'notSynced' => max(0, $craftProducts - (int)array_sum($counts)),
            'unpublished' => (int)ProductRecord::find()->where(['status' => SyncStatus::Unpublished->value])->count(),
            'shopifyProducts' => null,
        ];
    }

    /**
     * The records' entries, including trashed ones, in one query.
     *
     * @param ProductRecord[] $records
     * @return array<int, Entry>
     */
    private function entriesFor(array $records): array
    {
        if ($records === []) {
            return [];
        }

        return Entry::find()
            ->id(array_unique(array_column($records, 'entryId')))
            ->status(null)
            ->site('*')
            ->unique()
            ->trashed(null)
            ->indexBy('id')
            ->all();
    }

    /**
     * @return array{0: int|null, 1: string|null} Product count, or null and an error message.
     */
    private function shopifyProductCount(): array
    {
        $plugin = CraftShopifySync::getInstance();

        if (!$plugin->shopifyService->isConfigured()) {
            return [null, null];
        }

        try {
            return [$plugin->shopifyService->countProducts(), null];
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
    }
}
