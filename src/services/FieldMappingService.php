<?php

namespace roadsterworks\craftshopifysync\services;

use Craft;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets;
use craft\models\EntryType;
use craft\models\Section;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\MetafieldType;
use roadsterworks\craftshopifysync\enums\ShopifyAttribute;
use roadsterworks\craftshopifysync\errors\ShopifyApiException;
use roadsterworks\craftshopifysync\fields\ShopifyProduct;
use roadsterworks\craftshopifysync\models\FieldMapping;
use yii\base\Component;

/**
 * Reads and saves the product section's Craft field → Shopify mappings.
 */
class FieldMappingService extends Component
{
    /**
     * Everything the field mapping page needs to render.
     *
     * @param array<string, array<string, array<string, string>>>|null $posted Unsaved values to show instead of
     *     the stored mappings, e.g. after a failed save.
     *
     * @return array{
     *     section: array{name: string, handle: string}|null,
     *     entryTypes: array<int, array{name: string, handle: string, fields: array<int, array<string, mixed>>}>,
     *     targetOptions: array<int, array<string, string>>,
     *     metafieldTypeOptions: array<int, array{label: string, value: string}>,
     *     definitionsError: string|null
     * }
     */
    public function getMappingData(bool $refreshDefinitions = false, ?array $posted = null): array
    {
        [$definitions, $definitionsError] = $this->loadMetafieldDefinitions($refreshDefinitions);
        $definitionTargets = array_map(
            static fn(array $definition): string => FieldMapping::metafieldTarget($definition['namespace'], $definition['key'], $definition['type']),
            $definitions,
        );

        $section = $this->getSection();

        return [
            'section' => $section ? ['name' => $section->name, 'handle' => $section->handle] : null,
            'entryTypes' => array_map(
                fn(EntryType $entryType): array => $this->entryTypeData($entryType, $definitionTargets, $posted),
                $section?->getEntryTypes() ?? [],
            ),
            'targetOptions' => $this->targetOptions($definitions),
            'metafieldTypeOptions' => MetafieldType::options(),
            'definitionsError' => $definitionsError,
        ];
    }

    /**
     * Validate and save posted mappings.
     *
     * @param array<string, array<string, array{target?: string, namespace?: string, key?: string, type?: string}>> $posted
     *     Keyed by entry type handle, then Craft field handle.
     * @return string[] Validation errors; empty when the mappings were saved.
     */
    public function saveMappings(array $posted): array
    {
        $config = [];
        $errors = [];
        $definedTypes = $this->definedMetafieldTypes();

        foreach ($posted as $entryTypeHandle => $fields) {
            $destinations = [];

            foreach ($fields as $fieldHandle => $values) {
                $mapping = FieldMapping::fromTarget((string)$fieldHandle, (string)($values['target'] ?? ''), $values);

                if ($mapping === null) {
                    continue;
                }

                $prefix = "{$entryTypeHandle} → {$fieldHandle}";

                if (!$mapping->validate()) {
                    foreach ($mapping->getFirstErrors() as $error) {
                        $errors[] = "{$prefix}: {$error}";
                    }

                    continue;
                }

                if ($mapping->getShopifyAttribute()?->isMedia() && !Craft::$app->getFields()->getFieldByHandle((string)$fieldHandle) instanceof Assets) {
                    $errors[] = CraftShopifySync::t('{prefix}: images can only come from an Assets field.', ['prefix' => $prefix]);

                    continue;
                }

                // A metafield that already has a definition in Shopify only accepts the definition's type.
                $definedType = $mapping->isMetafield() ? ($definedTypes[$mapping->getDestinationLabel()] ?? null) : null;
                if ($definedType !== null && $definedType !== $mapping->type) {
                    $errors[] = CraftShopifySync::t('{prefix}: “{destination}” is defined in Shopify as {type}.', [
                        'prefix' => $prefix,
                        'destination' => $mapping->getDestinationLabel(),
                        'type' => $definedType,
                    ]);

                    continue;
                }

                $destination = $mapping->getDestinationKey();
                if (isset($destinations[$destination])) {
                    $errors[] = CraftShopifySync::t('{prefix}: “{destination}” is already mapped to “{field}”.', [
                        'prefix' => $prefix,
                        'destination' => $mapping->getDestinationLabel(),
                        'field' => $destinations[$destination],
                    ]);

                    continue;
                }

                $destinations[$destination] = $fieldHandle;
                $config[$entryTypeHandle][$fieldHandle] = $mapping->toConfig();
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $plugin = CraftShopifySync::getInstance();

        if (!$plugin->saveSettings(['fieldMappings' => $config])) {
            return [CraftShopifySync::t('Couldn’t save. Check the plugin settings are complete.')];
        }

        $plugin->logService->info('Field mappings saved', [
            'mappings' => array_map('count', $config),
        ]);

        return [];
    }

    /**
     * Shopify's metafield definition types, keyed by `namespace.key`. Empty when Shopify can't be reached.
     *
     * @return array<string, string>
     */
    private function definedMetafieldTypes(): array
    {
        [$definitions] = $this->loadMetafieldDefinitions(false);
        $types = [];

        foreach ($definitions as $definition) {
            $types["{$definition['namespace']}.{$definition['key']}"] = $definition['type'];
        }

        return $types;
    }

    private function getSection(): ?Section
    {
        $handle = CraftShopifySync::getInstance()->getSettings()->productSectionHandle;

        return $handle !== '' ? Craft::$app->getEntries()->getSectionByHandle($handle) : null;
    }

    /**
     * @param string[] $definitionTargets Select values of the metafield definitions offered in the dropdown.
     * @param array<string, array<string, array<string, string>>>|null $posted
     * @return array{name: string, handle: string, fields: array<int, array<string, mixed>>}
     */
    private function entryTypeData(EntryType $entryType, array $definitionTargets, ?array $posted): array
    {
        $mappings = [];
        foreach (CraftShopifySync::getInstance()->getSettings()->getFieldMappings($entryType->handle) as $mapping) {
            $mappings[$mapping->craftField] = $mapping;
        }

        $fields = [];
        foreach ($this->mappableFields($entryType) as $handle => [$name, $type]) {
            $values = $posted[$entryType->handle][$handle] ?? null;
            $mapping = $posted !== null
                ? FieldMapping::fromTarget($handle, (string)($values['target'] ?? ''), $values ?? [])
                : ($mappings[$handle] ?? null);
            $target = $mapping?->getTargetValue() ?? '';
            $isCustom = $mapping?->isMetafield() && !in_array($target, $definitionTargets, true);

            $fields[] = [
                'name' => $name,
                'handle' => $handle,
                'type' => $type,
                'mapping' => $mapping,
                'target' => $isCustom ? FieldMapping::CUSTOM_TARGET : $target,
            ];
        }

        return [
            'name' => $entryType->name,
            'handle' => $entryType->handle,
            'fields' => $fields,
        ];
    }

    /**
     * Native attributes plus custom fields on the entry type, excluding the plugin's own Shopify tab and field.
     *
     * @return array<string, array{0: string, 1: string}> Field name and type label, keyed by handle.
     */
    private function mappableFields(EntryType $entryType): array
    {
        $fields = [
            'title' => [Craft::t('app', 'Title'), CraftShopifySync::t('Native')],
            'slug' => [Craft::t('app', 'Slug'), CraftShopifySync::t('Native')],
        ];
        $fieldLayoutService = CraftShopifySync::getInstance()->fieldLayoutService;

        foreach ($entryType->getFieldLayout()->getTabs() as $tab) {
            if ($fieldLayoutService->isShopifyTab($tab)) {
                continue;
            }

            foreach ($tab->getElements() as $element) {
                if (!$element instanceof CustomField) {
                    continue;
                }

                $field = $element->getField();
                if ($field instanceof ShopifyProduct || isset($fields[$field->handle])) {
                    continue;
                }

                $fields[$field->handle] = [$field->name, $field::displayName()];
            }
        }

        return $fields;
    }

    /**
     * Options for the Shopify target select, grouped into attributes and metafields.
     *
     * @param array<int, array{name: string, namespace: string, key: string, type: string}> $definitions
     * @return array<int, array<string, string>>
     */
    private function targetOptions(array $definitions): array
    {
        $options = [
            ['label' => CraftShopifySync::t('Not mapped'), 'value' => ''],
            ['optgroup' => CraftShopifySync::t('Product attributes')],
        ];

        foreach ([false, true] as $media) {
            if ($media) {
                $options[] = ['optgroup' => CraftShopifySync::t('Images (Assets fields)')];
            }

            foreach (ShopifyAttribute::cases() as $attribute) {
                if ($attribute->isMedia() === $media) {
                    $options[] = ['label' => $attribute->label(), 'value' => FieldMapping::attributeTarget($attribute)];
                }
            }
        }

        $options[] = ['optgroup' => CraftShopifySync::t('Metafields')];

        foreach ($definitions as $definition) {
            $options[] = [
                'label' => "{$definition['name']} ({$definition['namespace']}.{$definition['key']})",
                'value' => FieldMapping::metafieldTarget($definition['namespace'], $definition['key'], $definition['type']),
            ];
        }

        $options[] = ['label' => CraftShopifySync::t('Custom metafield…'), 'value' => FieldMapping::CUSTOM_TARGET];

        return $options;
    }

    /**
     * @return array{0: array<int, array{name: string, namespace: string, key: string, type: string}>, 1: string|null}
     *     Definitions, and an error message when they couldn't be loaded.
     */
    private function loadMetafieldDefinitions(bool $refresh): array
    {
        $shopify = CraftShopifySync::getInstance()->shopifyService;

        if (!$shopify->isConfigured()) {
            return [[], CraftShopifySync::t('Connect to Shopify to list your metafield definitions.')];
        }

        try {
            return [$shopify->getMetafieldDefinitions($refresh), null];
        } catch (ShopifyApiException $e) {
            CraftShopifySync::getInstance()->logService->error('Could not load metafield definitions', ['error' => $e->getMessage()]);

            return [[], CraftShopifySync::t('Couldn’t load metafield definitions: {error}', ['error' => $e->getMessage()])];
        }
    }
}
