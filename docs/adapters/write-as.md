# write.as POSSE destination

Outpost's write.as destination publishes a fan-out copy of each WordPress post on write.as as a markdown post on publish. The WordPress post stays canonical; the write.as copy carries a "originally appeared on" link back to the source.

Shipped in G5b (Phase G).

## Setup

1. **Get your API token.** In write.as, go to Settings → Get API Token (or `Account → Settings` depending on UI version). Copy the token.
2. **Configure Outpost.** WP admin → Outpost → Settings → API Keys. Fill in:
    - `Syndicate to write.as` (checkbox)
    - `write.as API token` — stored encrypted at rest
    - `write.as collection alias` (optional) — the URL slug of the blog you want to publish into. Leave blank to publish standalone posts owned by your account.
3. **Mark posts to syndicate.** In the Gutenberg post sidebar, toggle write.as on for posts that should fan out. The first scheduled dispatch fires 30 seconds after publish.

## What gets syndicated

- **Title:** the WordPress post title.
- **Body:** the post's block content rendered through `do_blocks()` + `wpautop()` to HTML, then converted to markdown by the shared content transformer (the same one Buttondown uses). Appended at the end: a markdown paragraph linking back to the WordPress permalink.

## Endpoint selection

- **Standalone post** (no collection alias set): `POST https://write.as/api/posts`. The post is owned by your account but not grouped into a blog.
- **Collection post** (collection alias set): `POST https://write.as/api/collections/{alias}/posts`. The post lands in your blog under your write.as username.

## Canonical link

write.as posts support custom slugs but have no `canonical_url` field. Outpost appends a "This post originally appeared on …" paragraph in markdown form so feed readers and archive surfaces find the canonical WordPress URL.

## Failure handling

- **401 / 403:** auth failure (token revoked or wrong scope). Outpost marks the dispatch failed and surfaces an admin notice. **No retry** — fix the credentials first.
- **429, 502, 503, 504, network timeout:** transient. The G3.5b dispatcher retries up to two more times.
- **2xx with no URL in `data.url`:** falls back to constructing `https://write.as/{slug}` from `data.slug` when present. If neither is available, the dispatch is marked failed with `retryable=false`.

## What this is NOT

- **A federation toggle.** write.as has ActivityPub federation as a separate per-blog setting on write.as itself. Outpost's syndication landing the post on write.as is independent of whether that copy then federates further.
- **A subscriber manager.** write.as doesn't model subscribers in the email-newsletter sense.

## Reference

- API endpoints: `POST https://write.as/api/posts` (standalone) or `POST https://write.as/api/collections/{alias}/posts` (collection)
- Auth: `Authorization: Token {api_token}`
- Adapter class: `Outpost_POSSE_Destination_WriteAs` (`includes/posse/destinations/class-outpost-posse-destination-write-as.php`)
- Settings option storage: `outpost_settings_api_keys` (G3.5d API Keys tab)
