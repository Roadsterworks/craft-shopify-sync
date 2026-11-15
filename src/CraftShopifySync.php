<?php

namespace roadsterworks\craftshopifysync;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterElementDefaultTableAttributesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use Psr\Log\LogLevel;
use roadsterworks\craftshopifysync\elements\actions\SyncToShopify;
use roadsterworks\craftshopifysync\fields\ShopifyProduct;
use roadsterworks\craftshopifysync\enums\SyncStatus;
use roadsterworks\craftshopifysync\models\Settings;
use roadsterworks\craftshopifysync\records\Product as ProductRecord;
use roadsterworks\craftshopifysync\services\AuthService;
use roadsterworks\craftshopifysync\services\DashboardService;
use roadsterworks\craftshopifysync\services\FieldLayoutService;
use roadsterworks\craftshopifysync\services\FieldMappingService;
use roadsterworks\craftshopifysync\services\LogService;
use roadsterworks\craftshopifysync\services\PayloadService;
use roadsterworks\craftshopifysync\services\ShopifyService;
use roadsterworks\craftshopifysync\services\SyncService;
use yii\base\Event;
use yii\web\Response;

/**
 * One-way sync of Craft entries to Shopify products.
 *
 * @method static CraftShopifySync getInstance()
 * @method Settings getSettings()
 * @property-read AuthService $authService
 * @property-read DashboardService $dashboardService
 * @property-read FieldLayoutService $fieldLayoutService
 * @property-read FieldMappingService $fieldMappingService
 * @property-read LogService $logService
 * @property-read PayloadService $payloadService
 * @property-read ShopifyService $shopifyService
 * @property-read SyncService $syncService
 * @author Roadsterworks <roadsterworks@gmail.com>
 * @copyright Roadsterworks
 */
class CraftShopifySync extends Plugin
{
    public const HANDLE = 'craft-shopify-sync';

    public const PERMISSION_ACCESS = 'accessPlugin-craft-shopify-sync';
    public const PERMISSION_SYNC = 'syncProducts-craft-shopify-sync';
    public const PERMISSION_DELETE = 'deleteProducts-craft-shopify-sync';
    public const PERMISSION_FIELD_MAPPING = 'accessFieldMapping-craft-shopify-sync';
    public const PERMISSION_SETTINGS = 'manageSettings-craft-shopify-sync';

    private const COLUMN_STATUS = 'shopifySyncStatus';
    private const COLUMN_SYNCED = 'shopifySyncedAt';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'authService' => AuthService::class,
                'dashboardService' => DashboardService::class,
                'fieldLayoutService' => FieldLayoutService::class,
                'fieldMappingService' => FieldMappingService::class,
                'logService' => LogService::class,
                'payloadService' => PayloadService::class,
                'shopifyService' => ShopifyService::class,
                'syncService' => SyncService::class,
            ],
        ];
    }

    /**
     * Translate a message in the plugin's category.
     *
     * @param array<string, mixed> $params
     */
    public static function t(string $message, array $params = []): string
    {
        return Craft::t(self::HANDLE, $message, $params);
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();
        $this->registerCpHandlers();
        $this->registerEntryHandlers();
    }

    /**
     * Save settings, merging the given values into the current ones.
     *
     * Craft only persists the keys passed to `savePluginSettings()`, so the full settings array is always sent.
     *
     * @param array<string, mixed> $values
     */
    public function saveSettings(array $values): bool
    {
        return Craft::$app->getPlugins()->savePluginSettings($this, [...$this->getSettings()->toArray(), ...$values]);
    }

    public function getSettingsResponse(): Response
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl(self::HANDLE . '/settings'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = self::t('Shopify Sync');
        $nav['subnav'] = [];

        foreach ($this->cpPages() as $key => [$label, $permission]) {
            if (Craft::$app->getUser()->checkPermission($permission)) {
                $nav['subnav'][$key] = ['label' => $label, 'url' => self::HANDLE . ($key === 'dashboard' ? '' : "/{$key}")];
            }
        }

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Control panel pages, keyed by URL segment.
     *
     * @return array<string, array{0: string, 1: string}> Label and required permission.
     */
    private function cpPages(): array
    {
        return [
            'dashboard' => [self::t('Dashboard'), self::PERMISSION_ACCESS],
            'field-mapping' => [self::t('Field Mapping'), self::PERMISSION_FIELD_MAPPING],
            'settings' => [self::t('Settings'), self::PERMISSION_SETTINGS],
        ];
    }

    /**
     * The entry index source key of the product section.
     */
    private function productSource(): ?string
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($this->getSettings()->productSectionHandle);

        return $section ? "section:{$section->uid}" : null;
    }

    /**
     * Route plugin log messages to `storage/logs/craft-shopify-sync-YYYY-MM-DD.log`.
     */
    private function registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[LogService::CATEGORY] = new MonologTarget([
            'name' => LogService::CATEGORY,
            'categories' => [LogService::CATEGORY],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
        ]);
    }

    private function registerCpHandlers(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = ShopifyProduct::class;
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules[self::HANDLE] = self::HANDLE . '/dashboard/index';

            foreach ([...array_keys($this->cpPages()), 'auth'] as $page) {
                $event->rules[self::HANDLE . "/{$page}"] = self::HANDLE . "/{$page}/index";
            }
        });

        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => self::t('Shopify Sync'),
                'permissions' => [
                    self::PERMISSION_SYNC => ['label' => self::t('Sync products')],
                    self::PERMISSION_DELETE => ['label' => self::t('Delete all Shopify products')],
                    self::PERMISSION_FIELD_MAPPING => ['label' => self::t('Manage field mapping')],
                    self::PERMISSION_SETTINGS => ['label' => self::t('Manage settings')],
                ],
            ];
        });
    }

    /**
     * Keep Shopify in step with product entries as they are saved, deleted and restored.
     */
    private function registerEntryHandlers(): void
    {
        $sync = fn(): SyncService => $this->syncService;

        Event::on(Entry::class, Element::EVENT_AFTER_SAVE, fn(ModelEvent $e) => $sync()->handleEntrySaved($e->sender));
        Event::on(Entry::class, Element::EVENT_BEFORE_DELETE, function(ModelEvent $e) use ($sync) {
            // Another handler may already have cancelled the deletion.
            if ($e->isValid) {
                $sync()->handleEntryDeleting($e->sender);
            }
        });
        Event::on(Entry::class, Element::EVENT_AFTER_DELETE, fn(Event $e) => $sync()->handleEntryDeleted($e->sender));
        Event::on(Entry::class, Element::EVENT_AFTER_RESTORE, fn(Event $e) => $sync()->handleEntryRestored($e->sender));

        // Shopify columns in the entry index: available everywhere, shown by default in the product section.
        Event::on(Entry::class, Element::EVENT_REGISTER_TABLE_ATTRIBUTES, function(RegisterElementTableAttributesEvent $e) {
            $e->tableAttributes[self::COLUMN_STATUS] = ['label' => self::t('Shopify')];
            $e->tableAttributes[self::COLUMN_SYNCED] = ['label' => self::t('Last synced')];
        });

        Event::on(Entry::class, Element::EVENT_REGISTER_DEFAULT_TABLE_ATTRIBUTES, function(RegisterElementDefaultTableAttributesEvent $e) {
            if ($e->source === $this->productSource()) {
                array_unshift($e->tableAttributes, self::COLUMN_STATUS, self::COLUMN_SYNCED);
            }
        });

        Event::on(Entry::class, Element::EVENT_DEFINE_ATTRIBUTE_HTML, function(DefineAttributeHtmlEvent $e) {
            if ($e->attribute !== self::COLUMN_STATUS && $e->attribute !== self::COLUMN_SYNCED) {
                return;
            }

            $record = ProductRecord::forElement($e->sender);
            $e->html = $e->attribute === self::COLUMN_STATUS
                ? (string)SyncStatus::labelHtml($record?->getSyncStatus())
                : ($record?->getLastSyncedDate() ? Craft::$app->getFormatter()->asTimestamp($record->getLastSyncedDate(), 'short') : '');
        });

        // “Sync to Shopify” bulk action, in the product section's entry index.
        Event::on(Entry::class, Element::EVENT_REGISTER_ACTIONS, function(RegisterElementActionsEvent $e) {
            if ($e->source === $this->productSource() && Craft::$app->getUser()->checkPermission(self::PERMISSION_SYNC)) {
                $e->actions[] = SyncToShopify::class;
            }
        });
    }
}
