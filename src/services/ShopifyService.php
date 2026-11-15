<?php

namespace roadsterworks\craftshopifysync\services;

use Craft;
use Gnikyt\BasicShopifyAPI\BasicShopifyAPI;
use Gnikyt\BasicShopifyAPI\Options;
use Gnikyt\BasicShopifyAPI\ResponseAccess;
use Gnikyt\BasicShopifyAPI\Session;
use roadsterworks\craftshopifysync\CraftShopifySync;
use roadsterworks\craftshopifysync\errors\ShopifyApiException;
use roadsterworks\craftshopifysync\graphql\Gql;
use roadsterworks\craftshopifysync\models\Settings;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Shopify Admin GraphQL API client.
 *
 * Returns plain arrays and throws {@see ShopifyApiException} on transport, GraphQL or user errors. Requests are
 * rate limited by gnikyt/basic-shopify-api to stay within Shopify's query cost budget.
 */
class ShopifyService extends Component
{
    public const API_VERSION = '2026-07';
    public const MAX_PAGE_SIZE = 250;

    /** Batched mutations (e.g. 25 aliased deletes) can take several seconds. */
    private const TIMEOUT = 60;
    private const CONNECT_TIMEOUT = 10;

    /** Tag for cached Shopify data, invalidated when the connection settings change. */
    private const CACHE_TAG = 'craft-shopify-sync';
    private const CACHE_METAFIELD_DEFINITIONS = 'craft-shopify-sync:metafield-definitions';
    private ?BasicShopifyAPI $client = null;

    public function isConfigured(): bool
    {
        return CraftShopifySync::getInstance()->authService->isAuthorised();
    }

    /**
     * Run a GraphQL operation and return its `data`.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     * @throws ShopifyApiException
     */
    public function query(string $query, array $variables = []): array
    {
        $operation = preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $query, $matches) ? $matches[1] : 'anonymous';
        $started = hrtime(true);
        $response = $this->client()->graph($query, $variables);
        $durationMs = intdiv(hrtime(true) - $started, 1_000_000);

        if ($response['errors'] !== false) {
            $errors = $this->errorMessages($response['body'] ?? null);
            if (($response['status'] ?? null) === 401) {
                $errors[] = 'The app’s access was revoked. Authorise it again in the plugin settings.';
            }

            if (isset($response['exception'])) {
                $errors[] = $response['exception']->getMessage();
            }

            $this->log()->error("Shopify {$operation} failed", ['status' => $response['status'] ?? null, 'durationMs' => $durationMs, 'errors' => $errors]);

            throw new ShopifyApiException("Shopify {$operation} failed", $errors);
        }

        $body = $response['body'] instanceof ResponseAccess ? $response['body']->toArray() : [];
        $this->log()->info("Shopify {$operation}", ['durationMs' => $durationMs, 'cost' => $body['extensions']['cost']['actualQueryCost'] ?? null]);

        return $body['data'] ?? [];
    }

    /**
     * @throws ShopifyApiException
     */
    public function countProducts(): int
    {
        return (int)($this->query(Gql::PRODUCTS_COUNT)['productsCount']['count'] ?? 0);
    }

    /**
     * IDs of the first `$first` products in the store.
     *
     * @return string[]
     * @throws ShopifyApiException
     */
    public function fetchProductIds(int $first = self::MAX_PAGE_SIZE): array
    {
        $nodes = $this->query(Gql::PRODUCT_IDS, ['first' => max(1, min($first, self::MAX_PAGE_SIZE))])['products']['nodes'] ?? [];

        return array_values(array_filter(array_column($nodes, 'id')));
    }

    /**
     * @param array<string, mixed> $input ProductCreateInput.
     * @param array<int, array<string, string>>|null $media CreateMediaInput, in display order.
     * @return array{id: string, handle: string, status: string}
     * @throws ShopifyApiException
     */
    public function createProduct(array $input, ?array $media = null): array
    {
        return $this->mutate(Gql::PRODUCT_CREATE, 'productCreate', ['product' => $input, 'media' => $media ?: null])['product'];
    }

    /**
     * @param array<string, mixed> $input ProductUpdateInput, including the product `id`.
     * @param array<int, array<string, string>>|null $media CreateMediaInput to add, in display order.
     * @return array{id: string, handle: string, status: string}|null Null when the product no longer exists.
     * @throws ShopifyApiException
     */
    public function updateProduct(array $input, ?array $media = null): ?array
    {
        $result = $this->query(Gql::PRODUCT_UPDATE, ['product' => $input, 'media' => $media ?: null])['productUpdate'] ?? [];
        $userErrors = $result['userErrors'] ?? [];

        // An error on the `id` field means the product was deleted in Shopify.
        $errorFields = array_merge(...array_map(static fn(array $error): array => (array)($error['field'] ?? []), $userErrors));
        if (($result['product'] ?? null) === null && in_array('id', $errorFields, true)) {
            return null;
        }

        $this->assertNoUserErrors('productUpdate', $userErrors);

        return $result['product'];
    }

    /**
     * Remove all of a product's media, so Craft's images can replace them in order.
     *
     * @throws ShopifyApiException
     */
    public function clearProductMedia(string $productId): void
    {
        $ids = array_column($this->query(Gql::PRODUCT_MEDIA, ['id' => $productId])['product']['media']['nodes'] ?? [], 'id');

        if ($ids !== []) {
            $this->mutate(Gql::FILE_DELETE, 'fileDelete', ['fileIds' => $ids]);
        }
    }

    /**
     * @param array<int, array{namespace: string, key: string}> $identifiers
     * @throws ShopifyApiException
     */
    public function deleteMetafields(string $productId, array $identifiers): void
    {
        if ($identifiers !== []) {
            $this->mutate(Gql::METAFIELDS_DELETE, 'metafieldsDelete', [
                'metafields' => array_map(static fn(array $identifier): array => [...$identifier, 'ownerId' => $productId], $identifiers),
            ]);
        }
    }

    /**
     * Delete several products in one request.
     *
     * @param string[] $productIds
     * @return array{deleted: string[], errors: string[]}
     * @throws ShopifyApiException
     */
    public function deleteProducts(array $productIds): array
    {
        $productIds = array_values($productIds);
        $deleted = $errors = [];

        if ($productIds === []) {
            return ['deleted' => $deleted, 'errors' => $errors];
        }

        $variables = [];
        foreach ($productIds as $i => $id) {
            $variables["input{$i}"] = ['id' => $id];
        }

        $data = $this->query(Gql::bulkProductDelete(count($productIds)), $variables);

        foreach ($productIds as $i => $id) {
            $result = $data["d{$i}"] ?? [];

            if (!empty($result['deletedProductId'])) {
                $deleted[] = $result['deletedProductId'];
            }

            foreach ($this->errorMessages($result['userErrors'] ?? []) as $message) {
                $errors[] = "{$id}: {$message}";
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Product metafield definitions, cached for an hour.
     *
     * @return array<int, array{name: string, namespace: string, key: string, type: string}>
     * @throws ShopifyApiException
     */
    public function getMetafieldDefinitions(bool $refresh = false): array
    {
        if ($refresh) {
            Craft::$app->getCache()->delete(self::CACHE_METAFIELD_DEFINITIONS);
        }

        return Craft::$app->getCache()->getOrSet(self::CACHE_METAFIELD_DEFINITIONS, function(): array {
            $definitions = [];
            $cursor = null;

            do {
                $page = $this->query(Gql::METAFIELD_DEFINITIONS, ['after' => $cursor])['metafieldDefinitions'] ?? [];

                foreach ($page['nodes'] ?? [] as $node) {
                    $definitions[] = [...$node, 'type' => $node['type']['name']];
                }

                $cursor = $page['pageInfo']['endCursor'] ?? null;
            } while (($page['pageInfo']['hasNextPage'] ?? false) && $cursor !== null);

            return $definitions;
        }, 3600, $this->cacheDependency());
    }

    /**
     * Forget cached Shopify data and the API client.
     */
    public function clearCaches(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
        $this->client = null;
    }


    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> The mutation's payload.
     * @throws ShopifyApiException
     */
    private function mutate(string $mutation, string $operation, array $variables): array
    {
        $result = $this->query($mutation, $variables)[$operation] ?? [];
        $this->assertNoUserErrors($operation, $result['userErrors'] ?? []);

        return $result;
    }

    /**
     * @param array<int, array{field?: string[]|null, message?: string}> $userErrors
     * @throws ShopifyApiException
     */
    private function assertNoUserErrors(string $operation, array $userErrors): void
    {
        if ($userErrors !== []) {
            throw new ShopifyApiException("Shopify rejected {$operation}", $this->errorMessages($userErrors));
        }
    }

    /**
     * Messages from Shopify's various error shapes.
     *
     * @return string[]
     */
    private function errorMessages(mixed $errors): array
    {
        return match (true) {
            is_string($errors) => [$errors],
            is_array($errors) => array_values(array_map(
                static fn(mixed $error): string => is_array($error) ? (string)($error['message'] ?? json_encode($error)) : (string)$error,
                $errors,
            )),
            default => [],
        };
    }

    /**
     * @throws ShopifyApiException
     */
    private function client(): BasicShopifyAPI
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!$this->isConfigured()) {
            throw ShopifyApiException::notConfigured();
        }

        $options = (new Options())
            ->setType(true)
            ->setVersion(self::API_VERSION)
            ->setApiPassword(CraftShopifySync::getInstance()->authService->getAccessToken())
            ->setGuzzleOptions(['timeout' => self::TIMEOUT, 'connect_timeout' => self::CONNECT_TIMEOUT]);

        $this->client = new BasicShopifyAPI($options);
        $this->client->setSession(new Session($this->settings()->getStoreDomain()));

        return $this->client;
    }



    private function cacheDependency(): TagDependency
    {
        return new TagDependency(['tags' => [self::CACHE_TAG]]);
    }

    private function settings(): Settings
    {
        return CraftShopifySync::getInstance()->getSettings();
    }

    private function log(): LogService
    {
        return CraftShopifySync::getInstance()->logService;
    }
}
