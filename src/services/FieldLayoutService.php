<?php

namespace roadsterworks\craftshopifysync\services;

use Craft;
use craft\base\FieldInterface;
use craft\fieldlayoutelements\CustomField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\fields\ShopifyProduct;
use RuntimeException;
use yii\base\Component;

/**
 * Installs the Shopify Product field, on its own “Shopify” tab, on the product section's entry types.
 *
 * Entry types can be shared between sections, so other sections using them show the tab too.
 */
class FieldLayoutService extends Component
{
    public const TAB_NAME = 'Shopify';
    public const FIELD_HANDLE = 'shopifyProduct';

    /**
     * Add the field to a section's entry types, removing it from the previously configured section.
     *
     * @throws RuntimeException When the field or an entry type can't be saved.
     */
    public function install(string $sectionHandle, string $previousSectionHandle = ''): void
    {
        $field = $this->ensureField();

        if ($previousSectionHandle !== '' && $previousSectionHandle !== $sectionHandle) {
            $this->updateLayouts($this->sectionEntryTypes($previousSectionHandle), fn(FieldLayout $layout): bool => $this->removeField($layout, $field));
        }

        $this->updateLayouts($this->sectionEntryTypes($sectionHandle), fn(FieldLayout $layout): bool => $this->addField($layout, $field));

        CraftShopifySync::getInstance()->logService->info('Shopify field installed', [
            'section' => $sectionHandle,
            'previousSection' => $previousSectionHandle,
        ]);
    }

    /**
     * Remove the field (and any Shopify tab it leaves empty) from every entry type, then delete it.
     *
     * @throws RuntimeException When an entry type can't be saved.
     */
    public function uninstall(): void
    {
        $fieldsService = Craft::$app->getFields();
        $field = $fieldsService->getFieldByHandle(self::FIELD_HANDLE);

        if (!$field instanceof ShopifyProduct) {
            return;
        }

        $this->updateLayouts(Craft::$app->getEntries()->getAllEntryTypes(), fn(FieldLayout $layout): bool => $this->removeField($layout, $field));
        $fieldsService->deleteField($field);
    }

    /**
     * Whether a field layout tab is the plugin's own Shopify tab, so it can be excluded from mapping.
     */
    public function isShopifyTab(FieldLayoutTab $tab): bool
    {
        return $tab->name === self::TAB_NAME;
    }

    private function ensureField(): FieldInterface
    {
        $fieldsService = Craft::$app->getFields();
        $field = $fieldsService->getFieldByHandle(self::FIELD_HANDLE);

        if ($field instanceof ShopifyProduct) {
            return $field;
        }

        if ($field !== null) {
            throw new RuntimeException(sprintf('A field with the handle “%s” already exists and is not a Shopify Product field.', self::FIELD_HANDLE));
        }

        $field = $fieldsService->createField([
            'type' => ShopifyProduct::class,
            'name' => 'Shopify Product',
            'handle' => self::FIELD_HANDLE,
            'searchable' => false,
        ]);

        if (!$fieldsService->saveField($field)) {
            throw new RuntimeException('Could not create the Shopify Product field: ' . implode(' ', $field->getFirstErrors()));
        }

        return $field;
    }

    /**
     * @return EntryType[]
     */
    private function sectionEntryTypes(string $sectionHandle): array
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);

        if ($section === null) {
            CraftShopifySync::getInstance()->logService->warning('Section not found; skipping field layout update', ['section' => $sectionHandle]);

            return [];
        }

        return $section->getEntryTypes();
    }

    /**
     * Apply a change to the field layouts of the given entry types, saving those that changed.
     *
     * @param EntryType[] $entryTypes
     * @param callable(FieldLayout): bool $modify Returns whether the layout was changed.
     */
    private function updateLayouts(array $entryTypes, callable $modify): void
    {
        $entriesService = Craft::$app->getEntries();

        foreach ($entryTypes as $entryType) {
            $layout = $entryType->getFieldLayout();

            if (!$modify($layout)) {
                continue;
            }

            $entryType->setFieldLayout($layout);

            if (!$entriesService->saveEntryType($entryType)) {
                throw new RuntimeException(sprintf(
                    'Could not save entry type “%s”: %s',
                    $entryType->handle,
                    implode(' ', $entryType->getFirstErrors()),
                ));
            }
        }
    }

    private function addField(FieldLayout $layout, FieldInterface $field): bool
    {
        if ($this->containsField($layout, $field)) {
            return false;
        }

        $tabs = $layout->getTabs();
        $tab = $this->findShopifyTab($tabs);

        if ($tab === null) {
            $tab = new FieldLayoutTab(['name' => self::TAB_NAME, 'layout' => $layout, 'elements' => []]);
            $tabs[] = $tab;
        }

        // The tab already says “Shopify”, so the field's own label is hidden.
        $tab->setElements([...$tab->getElements(), new CustomField($field, ['label' => '__blank__'])]);
        $layout->setTabs($tabs);

        return true;
    }

    /**
     * Remove the field, and the Shopify tab too if nothing else is left on it.
     */
    private function removeField(FieldLayout $layout, FieldInterface $field): bool
    {
        if (!$this->containsField($layout, $field)) {
            return false;
        }

        $tabs = [];

        foreach ($layout->getTabs() as $tab) {
            $elements = array_values(array_filter(
                $tab->getElements(),
                static fn($element): bool => !($element instanceof CustomField && $element->getFieldUid() === $field->uid),
            ));

            if ($elements === [] && $this->isShopifyTab($tab)) {
                continue;
            }

            $tab->setElements($elements);
            $tabs[] = $tab;
        }

        $layout->setTabs($tabs);

        return true;
    }

    private function containsField(FieldLayout $layout, FieldInterface $field): bool
    {
        return $layout->isFieldIncluded(static fn($element): bool => $element instanceof CustomField && $element->getFieldUid() === $field->uid);
    }

    /**
     * @param FieldLayoutTab[] $tabs
     */
    private function findShopifyTab(array $tabs): ?FieldLayoutTab
    {
        foreach ($tabs as $tab) {
            if ($this->isShopifyTab($tab)) {
                return $tab;
            }
        }

        return null;
    }
}
