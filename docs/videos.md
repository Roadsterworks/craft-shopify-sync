# Video guides: recording plan

A short series of how-to videos for YouTube. Each is one task from start to finish, so people can jump to the one
they need. Aim for 2–6 minutes each. Once a video is up, add it to the **Video guides** table in the README.

## Before recording

- **Use a dev store** from your Shopify Partner organisation, with no real customers, and a fresh Craft install with
  a *Products* section and a few entries (title, description, a couple of images, a vendor, tags, a number field for
  a metafield).
- **Hide secrets.** Never show the client secret, the access token, or `.env`. Blur them in editing, or paste them off
  screen. If one does leak, rotate it in the Dev Dashboard.
- **Screen:** 1920 × 1080, browser zoom 125%, close other tabs, bookmarks bar hidden, notifications off.
- **Run the queue** in a terminal (`php craft queue/listen`) so syncs finish on camera instead of waiting for a page
  load.
- Have Craft and the Shopify admin in two tabs, so you can show a change landing in Shopify straight after saving.

## The series

### 1. Shopify Sync in 2 minutes

The trailer. Pin it to the channel and link it from the Plugin Store page.

1. The problem: product content managed in two places.
2. Edit an entry in Craft, save, switch to Shopify: it's updated.
3. Quick tour: dashboard, field mapping, the Shopify tab on an entry, the entries index columns.
4. Where to get it, and that it's free to try in development.

### 2. Installing and connecting to Shopify

1. Install from the Plugin Store (or `composer require`).
2. Copy the **App URL** from **Shopify Sync → Settings**.
3. In Shopify: **Dev Dashboard → Create app**, set the App URL, turn off *Embed app*, scopes
   `read_products,write_products`, release the version.
4. **Select distribution method → Custom distribution**, enter the store domain.
5. Copy the Client ID and Secret into `.env` *(off screen)*, reference them in Settings with `$SHOPIFY_…`.
6. Choose the product section, save, click **Authorise in Shopify**, approve, back in Craft connected.

Mention: you need a Partner organisation, or the **App developer** role on the store.

### 3. Mapping fields and metafields

1. **Field Mapping**: map description, vendor, product type, tags, SEO fields.
2. Map an Assets field to **Featured image** and another to **Images**.
3. Add a metafield definition in Shopify, click **Refresh metafields**, map a field to it.
4. Use **Custom metafield…** for one without a definition.
5. Open an entry's **Shopify** tab → **Data sent to Shopify** to check every value before syncing.
6. Show a validation error (for example text into a decimal metafield) and how it appears on the entry and dashboard.
7. **Re-sync all** after changing mappings.

### 4. Everyday syncing for editors

Aimed at content editors, not developers.

1. Save an entry: it syncs. The **Shopify** tab shows status and **View in Shopify**.
2. The entries index: Shopify status and last synced columns.
3. Select several entries → **Sync to Shopify**.
4. Disable an entry → unpublished in Shopify. Delete it → unpublished. Restore → back.
5. Dashboard: counts, **Needs attention**, **Retry**, recent activity.

### 5. Going live

1. Settings and mappings are project config: set up locally, deploy as usual.
2. Authorise on the live site, or share a token with `php craft craft-shopify-sync/auth/token` and
   `SHOPIFY_SYNC_ACCESS_TOKEN` *(token off screen)*.
3. Run the queue with a daemon in production.
4. First full sync: **Sync changes** on the dashboard, or `php craft craft-shopify-sync/sync/all`.
5. Permissions for editors: **Sync products** only.

### 6. Troubleshooting

Walk through the README's troubleshooting table: not connected, authorisation failing (App URL mismatch), access
revoked, nothing happening on save (queue not running), images not appearing (asset URLs must be public), where the
log file is.

### Later

When new features ship, record one each: **Prices and SKUs** (1.1) and **Variants** (1.2). See the
[roadmap](../ROADMAP.md).

## YouTube description template

```text
How to <task> with Shopify Sync for Craft CMS.

Shopify Sync turns Craft entries into Shopify products and sends any field to a product attribute or metafield.

Plugin Store: https://plugins.craftcms.com/craft-shopify-sync
Docs: https://github.com/roadsterworks/craft-shopify-sync#readme

Chapters
0:00 Intro
0:15 …
```

Use the same thumbnail style for the whole series (the plugin icon plus the video's number and title), and put the
videos in one playlist.
