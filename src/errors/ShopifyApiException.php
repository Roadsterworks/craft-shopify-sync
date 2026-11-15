<?php

namespace roadsterworks\craftshopifysync\errors;

use RuntimeException;
use Throwable;

/**
 * Raised when Shopify rejects a request or reports errors.
 */
class ShopifyApiException extends RuntimeException
{
    /**
     * @param string[] $errors Messages returned by Shopify.
     */
    public function __construct(string $message, public readonly array $errors = [], ?Throwable $previous = null)
    {
        parent::__construct($errors === [] ? $message : $message . ': ' . implode('; ', $errors), 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('Shopify isn’t connected. Finish the connection settings first');
    }
}
