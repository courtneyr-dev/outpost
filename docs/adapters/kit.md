# Kit POSSE destination

Outpost's Kit (formerly ConvertKit) destination publishes a fan-out copy of each WordPress post as a Kit broadcast on publish. The WordPress post stays canonical; the Kit broadcast carries a "originally appeared on" link back to the source.

Shipped in G5b (Phase G). The v3 API secret path lands here; OAuth v4 ("Connect Kit") is a separate follow-up PR.

## Setup

1. **Get your API secret.** In Kit, go to Settings → Advanced → API. Copy the **API Secret** (NOT the API Key). Broadcast creation requires the secret.
2. **Configure Outpost.** WP admin → Outpost → Settings → API Keys. Fill in:
    - `Syndicate to Kit` (checkbox)
    - `Kit API secret (v3)` — stored encrypted at rest
3. **Mark posts to syndicate.** In the Gutenberg post sidebar, toggle Kit on for posts that should fan out. The first scheduled dispatch fires 30 seconds after publish (G3.5b dispatcher).

## What gets syndicated

- **Subject:** the WordPress post title.
- **Content:** the post's block content rendered through `do_blocks()` + `wpautop()` to HTML. Appended at the end: a small paragraph linking back to the WordPress permalink.
- **Description:** a short "Syndicated from {permalink}" string surfaced in Kit's internal UI.
- **Status:** Kit's v3 broadcasts endpoint creates broadcasts as drafts by default; users send them manually from Kit when ready. This is intentional — broadcasts are paid-tier blasts, so auto-send isn't desirable for typical POSSE workflows.

## Canonical link

Kit's v3 broadcasts API has no native `canonical_url` field. Outpost appends a "This post originally appeared on …" paragraph to the broadcast content so subscribers and any archive surfaces find their way back to the canonical WordPress URL.

## Auth scheme — v3 secret in body, NOT a header

Kit's v3 API carries authentication as an `api_secret` field in the JSON request body — not in an `Authorization` header. The adapter builds the payload accordingly. Tests assert that no `Authorization` header is sent (which would either fail auth or land at v4 endpoints).

## Syndication URL fallback

Kit's create-broadcast response returns a `public_url` only when the broadcast is scheduled or sent — not when it's created as a draft. When `public_url` is empty, the adapter falls back to a stable `https://app.kit.com/broadcasts/{id}` URL so the dispatcher can still record the syndicated copy. After you push send in Kit, the public archive URL becomes available; updating the recorded URL is a future enhancement.

## Failure handling

- **401 / 403:** auth failure (most likely cause: you pasted the API Key instead of the API Secret). Outpost marks the dispatch failed and surfaces an admin notice. **No retry** — fix the credentials first.
- **429, 502, 503, 504, network timeout:** transient. The G3.5b dispatcher retries up to two more times.
- **2xx with no `broadcast.id`:** treated as failure with `retryable=false`.

## OAuth v4 follow-up

Kit's v4 API supports OAuth 2.0. The G5-kit-oauth follow-up PR will add a "Connect Kit" button that registers a Kit OAuth provider against the G3.5a `Outpost_Credentials_Store` and runs the standard authorization-code flow. The v3 API secret path will stay supported alongside — sites already configured don't need to migrate. The settings UI for v4 will live on the OAuth Connections page, not the API Keys tab.

## What this is NOT

- **A subscriber manager.** Outpost doesn't manage Kit subscribers, tags, or segments.
- **A v4 API integration.** This adapter uses v3 exclusively. v4 broadcasts use a different request shape and OAuth-based auth.

## Reference

- API endpoint: `POST https://api.convertkit.com/v3/broadcasts`
- Auth: `api_secret` field in JSON body (NOT an HTTP header)
- Adapter class: `Outpost_POSSE_Destination_Kit` (`includes/posse/destinations/class-outpost-posse-destination-kit.php`)
- Settings option storage: `outpost_settings_api_keys` (G3.5d API Keys tab)
