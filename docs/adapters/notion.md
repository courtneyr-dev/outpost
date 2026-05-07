# Notion adapter

G3.5a ships Notion as the proof-of-concept consumer of the OAuth + encrypted credentials foundation. Inbound only: shared Notion page URLs route to a fetch + bidirectional WP-blocks ↔ Notion-blocks converter. Outbound (creating Notion pages from Outpost posts) and webhook-driven sync are deferred to G8b.

## Setup

1. Register a Notion integration at <https://www.notion.so/profile/integrations> (Public OAuth).
2. Set the redirect URI to `https://your-site.example/wp-json/outpost/v1/oauth/notion/callback`.
3. Add the client credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_NOTION_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_NOTION_CLIENT_SECRET', 'yyy' );
   ```

4. Visit Settings → OAuth Connections in WP admin and click **Connect Notion**.
5. Authorize the Outpost integration in Notion. Pick the workspace + pages to grant access to.
6. The callback redirects you back to the settings page with `outpost_oauth_status=connected`.

Sharing a Notion page URL into Outpost (via share-target, paste, or bookmarklet) now resolves the page metadata + block tree from Notion's API.

## Notion API quirks Outpost handles

- **`Notion-Version: 2025-09-03` header** required on every request including the OAuth token endpoint. The provider override `extra_token_request_headers()` sends it.
- **Token endpoint uses HTTP Basic auth** (`Authorization: Basic <base64(client_id:client_secret)>`) instead of body parameters. Same override.
- **Tokens never expire.** The token response has no `expires_in`. The credentials store records `obtained_at` for audit only.
- **No RFC 7009 revocation endpoint.** `disconnect()` deletes local credentials; the integration's grant in Notion's UI is the only way to fully revoke.
- **No OAuth scopes.** Notion uses workspace-level capability flags managed in the Notion UI; OAuth scopes are empty.
- **Workspace identity in the token response.** The shape preserves `workspace_id`, `workspace_name`, `workspace_icon`, `bot_id`, and `owner` alongside `access_token` so callers can identify the connected workspace without a separate `users.me` call.

## URL patterns recognized

- `notion.so/...`
- `www.notion.so/...`
- `*.notion.site` (custom domains for shared pages)

## Page ID extraction

Notion page URLs come in two forms:

- Dashless 32-hex: `notion.so/Workspace-Name-abc123def456...` (32 hex chars).
- Dashed UUID: `notion.so/abc12345-6789-...`.

Both forms parse to the canonical UUID format the API requires.

## Block conversion

`Outpost_Notion_Blocks_Converter` is bidirectional and lossy by design:

| WP block                 | Notion block          | Notes |
|--------------------------|-----------------------|-------|
| `core/paragraph`         | `paragraph`           | |
| `core/heading` level 1   | `heading_1`           | |
| `core/heading` level 2   | `heading_2`           | |
| `core/heading` levels 3-6 | `heading_3`          | Notion has only 3 heading levels; 4-6 collapse with a debug log. |
| `core/quote`             | `quote`               | |
| `core/code`              | `code`                | Language defaults to `plain text`. |
| `core/list` (unordered)  | `bulleted_list_item` × N | List expands to one Notion block per `<li>`. |
| `core/list` (ordered)    | `numbered_list_item` × N | |
| `core/image`             | `image` (external URL) | Captions and alt text are not yet round-tripped. |
| Anything else            | `paragraph` (text passthrough) | Logs at debug level. |

Lossy conversions log to `error_log` when `WP_DEBUG` is on. The converter is not the place to surface user-facing warnings about lossiness — that belongs in the composer UI when round-tripping completes (deferred to G8b).

## Caching

Page fetches cache for 1 hour via WordPress transients keyed on the page ID. Re-share or re-paste the same URL during that window returns the cached snapshot. The cache invalidates on plugin upgrade (the version stamp is part of the transient key).

## Errors

- `outpost_notion_not_authenticated` — credentials missing for the user.
- `outpost_notion_page_not_shared` — Notion 404. The Notion integration may not be granted access to the page, or the page was deleted. Surface as a "share with Outpost integration in Notion" prompt.
- `outpost_notion_api_error` — non-2xx other than 404. Logged at debug level with the upstream status.

## Share-target preview behavior (G8b)

When a user shares a Notion URL into the Outpost composer, the preview endpoint (`/wp-json/outpost/v1/preview`) follows this dispatch:

1. **URL match.** Any URL on `notion.so`, `*.notion.site`, or `notion.com` matches the registered Notion source. The source's capabilities declare `auth_required: true`, which triggers G8b's authenticated-fetch branch.

2. **Connected user, page accessible.** Preview returns:
   ```json
   {
     "source_id": "notion",
     "authenticated_source": "notion",
     "authenticated_status": "ok",
     "extracted": {
       "p-name": "Page title from Notion",
       "u-photo": "https://example.com/cover.jpg",
       "p-summary": "First paragraph...\nA subhead\nMore text.",
       "notion-icon": "📓",
       "notion-page-id": "abc123def456...",
       "notion-block-count": 12
     },
     "raw": { /* full page + blocks payload */ }
   }
   ```
   The composer pre-fills `p-name` (page title) + `u-photo` (cover image) and surfaces the `p-summary` as the preview blurb.

3. **Connected user, page NOT shared with the Outpost integration.** Preview returns 200 with `authenticated_status: "page_not_shared"` and a user-friendly `authenticated_message` ("This Notion page hasn't been shared with Outpost. In Notion, click ••• → Add connections → Outpost."). The composer renders the message as a hint and falls back to whatever og:title scraping can extract from Notion's public meta (typically just the page title with " | Notion" suffix).

4. **Disconnected user (no Notion creds).** Preview falls through to the legacy og:title path. The composer can show a "Connect Notion for richer previews" hint based on the absence of `authenticated_source` in the response.

5. **Anonymous request.** Same as disconnected — falls through.

6. **Notion transport / 5xx failure.** Falls through silently to og:title; the user can retry or proceed with the URL alone.

The 1-hour transient cache on `Outpost_Source_Notion::fetch_page` rides on top of this dispatch — repeated shares of the same page within the cache window skip the API roundtrip entirely.

## What's deferred to follow-up PRs

- **Outbound:** creating Notion pages from Outpost posts (note → Notion page, photo → Notion page).
- **Webhook sync:** Notion → WP propagation when the source page changes.
- **Database queries:** fetching Notion database items, not just pages.
- **Block-level caption + alt-text round-trip.**
- **Composer-side UI** that consumes the new `authenticated_*` response fields (the response shape is in place; the composer's React/Preact components need to surface the icon, cover image, block count). The legacy `extracted` keys (`p-name`, `u-photo`, `p-summary`) work without UI changes.
