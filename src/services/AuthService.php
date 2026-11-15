<?php

namespace roadsterworks\craftshopifysync\services;

use Craft;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\db\Table;
use roadsterworks\craftshopifysync\errors\ShopifyApiException;
use roadsterworks\craftshopifysync\models\Settings;
use Throwable;
use yii\base\Component;
use yii\db\Query;

/**
 * Connects to Shopify with the OAuth authorisation code grant, for a Dev Dashboard app installed on the store.
 *
 * The permanent (offline) access token is encrypted with Craft's security key and stored per environment. Setting
 * the `SHOPIFY_SYNC_ACCESS_TOKEN` environment variable overrides it, e.g. to share one token between environments.
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/authorization-code-grant
 */
class AuthService extends Component
{
    public const SCOPES = 'read_products,write_products';
    public const TOKEN_ENV_VAR = 'SHOPIFY_SYNC_ACCESS_TOKEN';

    private const SESSION_STATE = 'craft-shopify-sync:oauth-state';

    private ?string $token = null;

    public function isAuthorised(): bool
    {
        return $this->settings()->hasCredentials() && $this->getAccessToken() !== '';
    }

    public function getAccessToken(): string
    {
        return $this->token ??= (string)App::env(self::TOKEN_ENV_VAR) ?: $this->storedToken();
    }

    /**
     * The Shopify URL where the merchant approves the app. The state is kept in the session to check the callback.
     */
    public function getAuthoriseUrl(): string
    {
        $settings = $this->settings();
        $state = StringHelper::randomString(32);
        Craft::$app->getSession()->set(self::SESSION_STATE, $state);

        return "https://{$settings->getStoreDomain()}/admin/oauth/authorize?" . http_build_query([
            'client_id' => $settings->getClientId(),
            'scope' => self::SCOPES,
            'redirect_uri' => $settings->getAuthUrl(),
            'state' => $state,
        ]);
    }

    /**
     * Exchange the code from Shopify's callback for an access token, and store it.
     *
     * @param array<string, mixed> $query
     * @throws ShopifyApiException
     */
    public function handleCallback(array $query): void
    {
        $settings = $this->settings();
        $expectedState = (string)Craft::$app->getSession()->remove(self::SESSION_STATE);

        if (
            $expectedState === '' ||
            !hash_equals($expectedState, (string)($query['state'] ?? '')) ||
            strtolower((string)($query['shop'] ?? '')) !== $settings->getStoreDomain() ||
            !$this->verifyRequest($query)
        ) {
            throw new ShopifyApiException('Shopify’s response couldn’t be verified. Try authorising again');
        }

        try {
            $body = Craft::createGuzzleClient(['timeout' => 30])
                ->post("https://{$settings->getStoreDomain()}/admin/oauth/access_token", [
                    'json' => ['client_id' => $settings->getClientId(), 'client_secret' => $settings->getClientSecret(), 'code' => (string)($query['code'] ?? '')],
                ])
                ->getBody();
            $token = (string)(json_decode((string)$body, true)['access_token'] ?? '');
        } catch (Throwable $e) {
            throw new ShopifyApiException('Shopify didn’t issue an access token', [$e->getMessage()], $e);
        }

        if ($token === '') {
            throw new ShopifyApiException('Shopify didn’t issue an access token');
        }

        Db::delete(Table::TOKENS);
        Db::insert(Table::TOKENS, [
            'shop' => $settings->getStoreDomain(),
            'accessToken' => base64_encode(Craft::$app->getSecurity()->encryptByKey($token)),
        ]);

        $this->token = $token;
        CraftShopifySync::getInstance()->shopifyService->clearCaches();
        CraftShopifySync::getInstance()->logService->info('Connected to Shopify', ['shop' => $settings->getStoreDomain()]);
    }

    /**
     * Whether a request was signed by Shopify with the app's secret: an HMAC-SHA256 of the other query parameters,
     * sorted by name.
     *
     * @param array<string, mixed> $query
     */
    public function verifyRequest(array $query): bool
    {
        $hmac = (string)($query['hmac'] ?? '');
        unset($query['hmac'], $query['signature']);
        ksort($query);

        return $hmac !== '' && hash_equals(hash_hmac('sha256', http_build_query($query), $this->settings()->getClientSecret()), $hmac);
    }

    public function disconnect(): void
    {
        Db::delete(Table::TOKENS);
        $this->token = null;
        CraftShopifySync::getInstance()->shopifyService->clearCaches();
    }

    private function storedToken(): string
    {
        $encrypted = (new Query())->select(['accessToken'])->from(Table::TOKENS)->where(['shop' => $this->settings()->getStoreDomain()])->scalar();

        if (!$encrypted) {
            return '';
        }

        try {
            return (string)Craft::$app->getSecurity()->decryptByKey(base64_decode((string)$encrypted));
        } catch (Throwable) {
            // Encrypted with another environment's security key.
            return '';
        }
    }

    private function settings(): Settings
    {
        return CraftShopifySync::getInstance()->getSettings();
    }
}
