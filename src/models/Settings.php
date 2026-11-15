<?php

namespace roadsterworks\craftshopifysync\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\enums\CreationStrategy;
use roadsterworks\craftshopifysync\enums\SyncStrategy;

/**
 * Plugin settings, stored in project config. Connection settings support environment variables.
 */
class Settings extends Model
{
    private const MYSHOPIFY_SUFFIX = '.myshopify.com';

    /** Env-parsed connection settings. Nullable because Craft sets an undefined env var to `null` during validation. */
    private const CREDENTIAL_ATTRIBUTES = ['shopifyStoreName', 'shopifyClientId', 'shopifyClientSecret'];

    /** Store name or `.myshopify.com` domain. */
    public ?string $shopifyStoreName = '';

    /** Client ID of the Shopify Dev Dashboard app. */
    public ?string $shopifyClientId = '';

    /** Client secret of the Shopify Dev Dashboard app. */
    public ?string $shopifyClientSecret = '';

    /** Handle of the section whose entries are synced as products. */
    public string $productSectionHandle = '';

    public bool $syncOnSave = true;

    /** @see CreationStrategy */
    public string $creationStrategy = CreationStrategy::Unpublished->value;

    /** @see SyncStrategy */
    public string $syncStrategy = SyncStrategy::Unpublish->value;

    /**
     * Craft field → Shopify mappings, keyed by entry type handle then Craft field handle.
     *
     * @var array<string, array<string, array<string, string>>>
     * @see FieldMapping::toConfig()
     */
    public array $fieldMappings = [];

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => self::CREDENTIAL_ATTRIBUTES,
            ],
        ];
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [[...self::CREDENTIAL_ATTRIBUTES, 'productSectionHandle'], 'required'],
            [self::CREDENTIAL_ATTRIBUTES, 'string'],
            [['shopifyStoreName'], 'match', 'pattern' => '/^(https?:\/\/)?[a-z0-9][a-z0-9-]*(\.myshopify\.com)?\/?$/i', 'message' => CraftShopifySync::t('Use your store’s `.myshopify.com` domain.')],
            [['syncOnSave'], 'boolean'],
            [['creationStrategy'], 'in', 'range' => array_column(CreationStrategy::cases(), 'value')],
            [['syncStrategy'], 'in', 'range' => array_column(SyncStrategy::cases(), 'value')],
            [['fieldMappings'], 'safe'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return array_map(CraftShopifySync::t(...), [
            'shopifyStoreName' => 'Store',
            'shopifyClientId' => 'Client ID',
            'shopifyClientSecret' => 'Client secret',
            'productSectionHandle' => 'Product section',
            'syncOnSave' => 'Sync on save',
            'creationStrategy' => 'New products',
            'syncStrategy' => 'Deleted entries',
        ]);
    }

    /**
     * The store's `.myshopify.com` domain, without any scheme or trailing slash.
     */
    public function getStoreDomain(): string
    {
        $domain = strtolower(rtrim((string)preg_replace('#^https?://#i', '', $this->env($this->shopifyStoreName)), '/'));

        return $domain === '' || str_ends_with($domain, self::MYSHOPIFY_SUFFIX) ? $domain : $domain . self::MYSHOPIFY_SUFFIX;
    }

    /**
     * The store handle used in Shopify admin URLs.
     */
    public function getStoreHandle(): string
    {
        return substr($this->getStoreDomain(), 0, -strlen(self::MYSHOPIFY_SUFFIX));
    }

    public function getClientId(): string
    {
        return $this->env($this->shopifyClientId);
    }

    public function getClientSecret(): string
    {
        return $this->env($this->shopifyClientSecret);
    }

    /**
     * Whether the store, client ID and secret are set, so the app can be authorised.
     */
    public function hasCredentials(): bool
    {
        return $this->getStoreDomain() !== '' && $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    /**
     * Where Shopify sends the merchant after they approve the app: the app's “App URL”.
     */
    public function getAuthUrl(): string
    {
        return UrlHelper::cpUrl(CraftShopifySync::HANDLE . '/auth');
    }

    /**
     * Whether syncing can run: connected to Shopify and a product section chosen.
     */
    public function isReady(): bool
    {
        return $this->productSectionHandle !== '' && CraftShopifySync::getInstance()->authService->isAuthorised();
    }

    public function getCreationStrategy(): CreationStrategy
    {
        return CreationStrategy::tryFrom($this->creationStrategy) ?? CreationStrategy::Unpublished;
    }

    public function getSyncStrategy(): SyncStrategy
    {
        return SyncStrategy::tryFrom($this->syncStrategy) ?? SyncStrategy::Unpublish;
    }

    /**
     * @return FieldMapping[]
     */
    public function getFieldMappings(string $entryTypeHandle): array
    {
        return array_map(
            static fn(array $config): FieldMapping => new FieldMapping($config),
            array_values($this->fieldMappings[$entryTypeHandle] ?? []),
        );
    }

    private function env(?string $value): string
    {
        return trim((string)App::parseEnv($value));
    }
}
