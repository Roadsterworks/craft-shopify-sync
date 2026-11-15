<?php

namespace roadsterworks\craftshopifysync\graphql;

/**
 * Shopify Admin API GraphQL operations.
 */
final class Gql
{
    private const PRODUCT_RESULT = '{ product { id handle status } userErrors { field message } }';

    public const PRODUCTS_COUNT = 'query productsCount { productsCount(limit: null) { count } }';

    /**
     * No cursor on purpose: when deleting, removed products drop out of the next call.
     */
    public const PRODUCT_IDS = 'query productIds($first: Int!) { products(first: $first) { nodes { id } } }';

    public const METAFIELD_DEFINITIONS = <<<'GRAPHQL'
        query metafieldDefinitions($after: String) {
            metafieldDefinitions(ownerType: PRODUCT, first: 250, after: $after) {
                nodes { name namespace key type { name } }
                pageInfo { hasNextPage endCursor }
            }
        }
        GRAPHQL;

    public const PRODUCT_CREATE = 'mutation productCreate($product: ProductCreateInput!, $media: [CreateMediaInput!]) '
        . '{ productCreate(product: $product, media: $media) ' . self::PRODUCT_RESULT . ' }';

    /** `$media` is added to the product's existing media. */
    public const PRODUCT_UPDATE = 'mutation productUpdate($product: ProductUpdateInput!, $media: [CreateMediaInput!]) '
        . '{ productUpdate(product: $product, media: $media) ' . self::PRODUCT_RESULT . ' }';

    public const PRODUCT_MEDIA = 'query productMedia($id: ID!) { product(id: $id) { media(first: 250) { nodes { id } } } }';

    public const FILE_DELETE = 'mutation fileDelete($fileIds: [ID!]!) { fileDelete(fileIds: $fileIds) { userErrors { field message } } }';

    public const METAFIELDS_DELETE = 'mutation metafieldsDelete($metafields: [MetafieldIdentifierInput!]!) '
        . '{ metafieldsDelete(metafields: $metafields) { userErrors { field message } } }';

    /**
     * Delete several products in one request by aliasing `productDelete` as `d0`, `d1`, …, each taking an
     * `$input{n}` variable.
     */
    public static function bulkProductDelete(int $count): string
    {
        $declarations = $fields = [];

        for ($i = 0; $i < $count; $i++) {
            $declarations[] = "\$input{$i}: ProductDeleteInput!";
            $fields[] = "d{$i}: productDelete(input: \$input{$i}) { deletedProductId userErrors { field message } }";
        }

        return 'mutation bulkProductDelete(' . implode(', ', $declarations) . ') { ' . implode(' ', $fields) . ' }';
    }
}
