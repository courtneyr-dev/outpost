# Notion adapter

G3.5a ships Notion as the proof-of-concept consumer of the OAuth + encrypted credentials foundation. Inbound only: shared Notion page URLs route to a fetch + bidirectional WP-blocks ↔ Notion-blocks converter. Outbound (creating Notion pages from Outpost posts) and webhook-driven sync aren't available.

## Setup

1. Register a Notion integration at <https://www.notion.so/profile/integrations> (Public OAuth).
2. Set the redirect URI to `https://your-site.example/wp-json/outpost/v1/oauth/notion/callback`.
3. Add the client credentials to `wp-config.php`:

   ```php
   define( 'OUTPOST_NOTION_CLIENT_ID', 'xxx' );
   define( 'OUTPOST_NOTION_CLIENT_SECRET', 'yyy' );
   ```

4. In wp-admin, go to **Outpost → OAuth Connections** and click **Connect Notion**.
5. Authorize the Outpost integration in Notion. Pick the workspace + pages to grant access to.
6. The callback redirects you back to the OAuth Connections page (`admin.php?page=outpost-oauth`) with `outpost_oauth_status=connected`.

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
- `{workspace}.notion.site/...` (public workspace subdomains)

`Outpost_Source_Notion::matches_url()` also requires a 32-hex page ID at the end of the URL. `notion.com` URLs don't match.

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

Lossy conversions log to `error_log` when `WP_DEBUG` is on. No user-facing warning about lossy conversion exists.

## Caching

Page fetches cache for 1 hour (`HOUR_IN_SECONDS`) in a transient keyed `outpost_notion_page_{page_id}_{user_id}_{token fingerprint}`, where the fingerprint is the first 16 hex characters of a SHA-256 of the access token. Re-sharing the same URL within the hour returns the cached snapshot to the same user. Each user gets their own entry, and reconnecting Notion changes the token and so the key. The key has no plugin-version stamp, so upgrading Outpost doesn't clear the cache. Outpost checks the user's credentials before reading the cache, so a disconnected user never gets a cached page.

## Errors

`Outpost_Source_Notion::fetch_page()` returns these `WP_Error` codes:

- `outpost_notion_invalid_url` — no 32-hex page ID in the URL.
- `outpost_notion_not_connected` — no Notion credentials for the user.
- `outpost_notion_transport` — `wp_remote_get()` failed (network error or timeout).
- `outpost_notion_page_not_shared` — Notion 404. The integration hasn't been granted access to the page, or the page was deleted. The message tells the user to add the Outpost connection in Notion.
- `outpost_notion_unauthorized` — Notion 401 or 403. The message tells the user to reconnect.
- `outpost_notion_http` — any other non-2xx status; the status is in the error data as `status`.
- `outpost_notion_parse` — a 2xx response whose body isn't JSON.

## Share-target preview behavior (G8b)

When a user shares a Notion URL into the Outpost composer, the preview endpoint (`/wp-json/outpost/v1/preview`) follows this dispatch:

1. **URL match.** A URL on `notion.so`, `www.notion.so`, or `{workspace}.notion.site` that ends in a page ID matches the registered Notion source. The source's capabilities declare `auth_required: true`, which triggers G8b's authenticated-fetch branch.

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

3. **Connected user, page NOT shared with the Outpost integration.** Preview returns 200 with `authenticated_status: "page_not_shared"` and a user-friendly `authenticated_message` ("This Notion page hasn't been shared with Outpost. In Notion, click ••• → Add connections → Outpost."). `extracted` and `raw` are empty in this response; this branch doesn't fall back to og:title scraping.

4. **Disconnected user (no Notion creds).** Preview falls through to the legacy og:title path. The composer can show a "Connect Notion for richer previews" hint based on the absence of `authenticated_source` in the response.

5. **Anonymous request.** Same as disconnected — falls through.

6. **Any other Notion error** (`outpost_notion_unauthorized`, `_transport`, `_http`, `_parse`, `_invalid_url`). Falls through silently to og:title; the user can retry or proceed with the URL alone.

The 1-hour transient cache on `Outpost_Source_Notion::fetch_page` rides on top of this dispatch — repeated shares of the same page within the cache window skip the API roundtrip entirely.

## Not available yet

- **Outbound:** creating Notion pages from Outpost posts (note → Notion page, photo → Notion page).
- **Webhook sync:** Notion → WP propagation when the source page changes.
- **Database queries:** fetching Notion database items, not just pages.
- **Block-level caption + alt-text round-trip.**
- **Composer-side UI** that consumes the new `authenticated_*` response fields (the response shape is in place; the composer's React/Preact components need to surface the icon, cover image, block count). The legacy `extracted` keys (`p-name`, `u-photo`, `p-summary`) work without UI changes.
