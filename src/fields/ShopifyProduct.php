<?php

namespace roadsterworks\craftshopifysync\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\elements\Entry;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\SyncStatus;
use roadsterworks\craftshopifysync\records\Product as ProductRecord;
use roadsterworks\craftshopifysync\web\assets\cp\CpAsset;
use Throwable;

/**
 * Read-only field showing an entry's Shopify sync status, with a button to sync it now.
 * Stores no content: the relationship lives in {@see ProductRecord}.
 */
class ShopifyProduct extends Field
{
    public static function displayName(): string
    {
        return CraftShopifySync::t('Shopify Product');
    }

    public static function icon(): string
    {
        return 'cart-shopping';
    }

    public static function dbType(): array|string|null
    {
        return null;
    }

    public static function isRequirable(): bool
    {
        return false;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        return $this->renderInput($element, false);
    }

    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        return $this->renderInput($element, true);
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return (string)SyncStatus::labelHtml(ProductRecord::forElement($element)?->getSyncStatus());
    }

    private function renderInput(?ElementInterface $element, bool $static): string
    {
        $entry = $element instanceof Entry ? $element : null;
        $entryId = $entry && !$entry->getIsUnpublishedDraft() ? $entry->getCanonicalId() : null;
        $record = $entryId ? ProductRecord::findOne(['entryId' => $entryId]) : null;
        $plugin = CraftShopifySync::getInstance();
        $rows = [];
        $previewError = null;
        $upToDate = null;

        if ($entry) {
            try {
                $payload = $plugin->payloadService->build($entry);
                $rows = $plugin->payloadService->previewRows($payload);
                $upToDate = $record?->getSyncStatus() === SyncStatus::Synced ? $record->contentHash === $payload->hash() : null;
            } catch (Throwable $e) {
                $previewError = $e->getMessage();
            }
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(CpAsset::class);

        return $view->renderTemplate('craft-shopify-sync/_field/input', [
            'entryId' => $entryId,
            'isDraft' => $entry?->getIsDraft() ?? false,
            'record' => $record,
            'statusHtml' => SyncStatus::labelHtml($record?->getSyncStatus()),
            'upToDate' => $upToDate,
            'rows' => $rows,
            'previewError' => $previewError,
            'canSync' => !$static && Craft::$app->getUser()->checkPermission(CraftShopifySync::PERMISSION_SYNC),
        ]);
    }
}
