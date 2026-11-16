# Release Notes for Shopify Sync

## 1.0.1 - 2026-11-16

- Added a [roadmap](ROADMAP.md): prices and SKUs, then variants.
- Excluded docs, screenshots and development config from Composer installs.

## 1.0.0 - 2026-11-15

- Initial release.
- Sync entries in a chosen section to Shopify products, with Craft as the source of truth.
- Connect with a Shopify Dev Dashboard app using OAuth. The access token is encrypted in the database, and `craft-shopify-sync/auth/token` shares it with other environments via `SHOPIFY_SYNC_ACCESS_TOKEN`.
- Map Craft fields per entry type to product attributes (title, handle, description, vendor, product type, tags, SEO) or to any metafield, from your store's definitions or entered by hand.
- Sync images from Assets fields: a featured image and a gallery, in order. Images are only re-uploaded when they change.
- Mappings are checked on save, including custom metafields against the types of existing Shopify definitions.
- The product section's entry index shows Shopify status and last synced columns by default.
- “Sync to Shopify” bulk action in the product section's entry index.
- Each product entry gets a Shopify tab showing its sync status, whether it has unsynced changes, any error from Shopify, a “Sync now” button and a preview of exactly what will be sent.
- Craft values are converted to Shopify formats: text, rich text, option labels, numbers, booleans, dates, relations, asset URLs, JSON and product references to other synced entries.
- Everything runs in the queue. Unchanged products are skipped, full syncs are batched with relation fields eager-loaded, and single-entry syncs run ahead of bulk ones.
- Deleted entries unpublish (or delete) their products, and restored entries are synced again. Products deleted in Shopify are recreated on the next sync.
- Live dashboard with sync counts, Shopify's product count, a “Needs attention” list of failed products with Retry buttons, and recent activity.
- Console commands: `craft-shopify-sync/sync/all [--force]`, `craft-shopify-sync/sync/entry <id>` and `craft-shopify-sync/auth/token`.
- Permissions for syncing, field mapping, settings and deleting all Shopify products.
- Own log file: `storage/logs/craft-shopify-sync-YYYY-MM-DD.log`.
