<?php

namespace roadsterworks\craftshopifysync\records;

use craft\base\ElementInterface;
use craft\db\ActiveRecord;
use craft\helpers\DateTimeHelper;
use DateTime;
use Twig\Markup;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\db\Table;
use roadsterworks\craftshopifysync\enums\SyncStatus;

/**
 * Links a Craft entry to its Shopify product. Deleted with the entry via a cascading foreign key.
 *
 * @property int $id
 * @property int $entryId
 * @property string|null $shopifyId Product GID, e.g. `gid://shopify/Product/123`.
 * @property string|null $shopifyHandle
 * @property string $status {@see SyncStatus}
 * @property string|null $contentHash Hash of the last payload sent to Shopify.
 * @property string|null $mediaHash Hash of the images last sent, when images are mapped.
 * @property string|null $lastError
 * @property DateTime|string|null $lastSyncedAt
 * @property DateTime|string|null $unpublishedAt
 * @property DateTime|string $dateCreated
 * @property DateTime|string $dateUpdated
 * @property string $uid
 */
class Product extends ActiveRecord
{
    /** @var array<int, self|null> Records keyed by entry ID, loaded a page of entries at a time. */
    private static array $byEntry = [];

    /**
     * The record for an entry in an index or list, loading its sibling entries' records in the same query.
     */
    public static function forElement(ElementInterface $element): ?self
    {
        $id = (int)$element->getCanonicalId();

        if (!array_key_exists($id, self::$byEntry)) {
            $ids = array_map(static fn(ElementInterface $sibling): int => (int)$sibling->getCanonicalId(), $element->elementQueryResult ?? [$element]);
            /** @var array<int, self> $records */
            $records = self::find()->where(['entryId' => $ids])->indexBy('entryId')->all();
            self::$byEntry = $records + array_fill_keys($ids, null) + self::$byEntry;
        }

        return self::$byEntry[$id];
    }

    public static function tableName(): string
    {
        return Table::PRODUCTS;
    }

    public static function findOrNew(int $entryId): self
    {
        return self::findOne(['entryId' => $entryId]) ?? new self(['entryId' => $entryId]);
    }

    public function getSyncStatus(): ?SyncStatus
    {
        return SyncStatus::tryFrom((string)$this->status);
    }

    public function getStatusHtml(): Markup
    {
        return SyncStatus::labelHtml($this->getSyncStatus());
    }

    public function getLastSyncedDate(): ?DateTime
    {
        return DateTimeHelper::toDateTime($this->lastSyncedAt) ?: null;
    }

    public function isLinked(): bool
    {
        return !empty($this->shopifyId);
    }

    /**
     * Link to the product in Shopify admin.
     */
    public function getAdminUrl(): ?string
    {
        if (!$this->isLinked()) {
            return null;
        }

        $store = CraftShopifySync::getInstance()->getSettings()->getStoreHandle();
        $id = substr((string)$this->shopifyId, (int)strrpos((string)$this->shopifyId, '/') + 1);

        return "https://admin.shopify.com/store/{$store}/products/{$id}";
    }
}
