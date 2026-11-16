# Roadmap

What's planned for Shopify Sync, roughly in order. There are no dates, and priorities shift with what people ask
for, so [open an issue](https://github.com/roadsterworks/craft-shopify-sync/issues) if something here matters to you
or is missing.

Craft stays the source of truth throughout: everything below is still a one-way sync from Craft to Shopify.

## 1.1 · Prices and SKUs

Most products have one variant. 1.1 lets you manage that variant from Craft, using the same field mapping as
everything else.

- **New mapping destinations** for the product's default variant: price, compare-at price, SKU, barcode, weight (with
  unit), cost per item, *charge tax* and *requires shipping*.
- **Easy for editors to keep track of.** They're ordinary Craft fields, so they can be columns in the entries index,
  and they're listed in the Shopify tab's *Data sent to Shopify* preview.
- **Duplicate SKU warnings** when two entries in the product section share a SKU.
- **Clear errors.** Shopify's validation messages (for example a negative price) are shown on the entry and the
  dashboard, as they are now.

Inventory quantities stay in Shopify. Stock changes all day from orders, returns and POS, and Craft would be
overwriting it with out-of-date numbers. Tracking can be switched on per product, but counts are managed in Shopify.

## 1.2 · Variants

For products that come in sizes, colours and so on.

- **Variants from a Matrix field.** Each nested entry becomes a variant, with its option values (for example *Size:
  Large*, *Colour: Red*), price, SKU, barcode, weight and image.
- Product options are worked out from the variants, so there's nothing extra to set up.
- Variants are matched by SKU, so reordering or editing them updates the existing variants in Shopify rather than
  recreating them, and their order history is kept.
- Shopify's limits (3 options, 2,048 variants per product) are checked before anything is sent.

## Later

Ideas being considered, in no particular order:

- **Images from local and private volumes.** Upload asset files to Shopify directly, so images sync from local
  development sites and volumes without public URLs.
- **Changed in Shopify warnings.** Use Shopify webhooks to flag products that were edited or deleted in Shopify, on the
  entry and the dashboard, before the next sync overwrites them.
- **Stock at a glance.** Show each product's inventory from Shopify, read-only, on the entry's Shopify tab.
- **Collections.** Map an Entries or Categories field to Shopify collections.
- **Sales channels.** Choose which channels new products are published to.
- **Translations.** Send other sites' content to Shopify's translations for multi-language stores.

## Not planned

- **Two-way sync.** Craft is the source of truth; syncing edits back from Shopify brings conflicts that are hard for
  editors to reason about.
- **Inventory management in Craft.** See above: Shopify does this better.
- **Craft Commerce.** Shopify Sync is for sites that sell through Shopify.
