<?php

namespace roadsterworks\craftshopifysync\models;

use craft\helpers\Json;

/**
 * Product data built from an entry, ready to send to Shopify.
 */
final readonly class ProductPayload
{
    /**
     * @param array<string, mixed> $productInput Core ProductInput attributes (title, handle, seo, …).
     * @param array<int, array{namespace: string, key: string, type: string, value: string}> $metafields
     * @param array<int, array{namespace: string, key: string}> $clearedMetafields Mapped metafields with an empty value.
     * @param array<int, array{originalSource: string, alt: string, mediaContentType: string}>|null $media
     *     Product images, featured image first. Null when no image destination is mapped, so Shopify's media is left alone.
     */
    public function __construct(
        public array $productInput,
        public array $metafields = [],
        public array $clearedMetafields = [],
        public ?array $media = null,
    ) {
    }

    /**
     * Fingerprint used to skip syncs when nothing has changed.
     */
    public function hash(): string
    {
        return hash('sha256', Json::encode([$this->productInput, $this->metafields, $this->clearedMetafields, $this->media]));
    }

    /**
     * Fingerprint of the images alone, so they're only re-uploaded when they change.
     */
    public function mediaHash(): ?string
    {
        return $this->media === null ? null : hash('sha256', Json::encode($this->media));
    }

    /**
     * ProductCreateInput / ProductUpdateInput.
     *
     * @param array<string, mixed> $extra E.g. `id` or `status`.
     * @return array<string, mixed>
     */
    public function toInput(array $extra = []): array
    {
        $input = [...$this->productInput, ...$extra];

        if ($this->metafields !== []) {
            $input['metafields'] = $this->metafields;
        }

        return $input;
    }
}
