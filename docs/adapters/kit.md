# Kit POSSE destination

Outpost's Kit (formerly ConvertKit) destination publishes a fan-out copy of each WordPress post as a Kit broadcast on publish. The WordPress post stays canonical; the Kit broadcast carries a "originally appeared on" link back to the source.

Shipped in G5b (Phase G). The adapter uses Kit's v3 API secret; OAuth v4 isn't available.

## Setup

1. **Set the encryption key.** Outpost encrypts the API secret at rest, and the API Keys tab hides its form, showing "Outpost encryption key is not configured", until a key exists. Add the constant to `wp-config.php`; it must be 32 raw bytes, so wrap the base64 value in `base64_decode()`:

    ```php
    // Generate the value once with: php -r "echo base64_encode(random_bytes(32));"
    define( 'OUTPOST_ENCRYPTION_KEY', base64_decode( 'paste-the-output-here' ) );
    ```

    See [Encryption key](../concepts/encryption-key.md) for the database fallback.
2. **Get your API secret.** In Kit, go to Settings → Advanced → API. Copy the **API Secret** (NOT the API Key). Broadcast creation requires the secret.
3. **Configure Outpost.** WP admin → Outpost → Settings → API Keys. Fill in:
    - `Syndicate to Kit` (checkbox)
    - `Kit API secret (v3)` — stored encrypted at rest
4. **Mark posts to syndicate.** The block editor has no destination selector yet. Set the `_outpost_posse_targets` post meta to a list of destination ids that includes `kit`, before the post is published. The REST route is `POST /wp-json/wp/v2/posts/<id>`, and the request needs a user with `edit_post` on that post. Set `WP_USER` to your username and `WP_APP_PASSWORD` to an application password (Users → Profile → Application Passwords) first:

    ```bash
    curl -X POST https://example.com/wp-json/wp/v2/posts/123 \
      -u "$WP_USER:$WP_APP_PASSWORD" \
      -H 'Content-Type: application/json' \
      -d '{"meta":{"_outpost_posse_targets":["kit"]}}'
    ```

    WP-CLI works too: `wp post meta update 123 _outpost_posse_targets '["kit"]' --format=json`. The meta is registered for the `post` post type only, and the post's author needs `publish_posts`. When the post moves into `publish`, the dispatcher schedules the first attempt 30 seconds later. Edits to a post that's already published don't dispatch again.

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

Kit's create-broadcast response returns a `public_url` only when the broadcast is scheduled or sent — not when it's created as a draft. When `public_url` is empty, the adapter falls back to a stable `https://app.kit.com/broadcasts/{id}` URL so the dispatcher can still record the syndicated copy. After you send the broadcast in Kit, the public archive URL exists, but Outpost doesn't update the recorded URL; it keeps the `app.kit.com` link.

## Failure handling

- **401 / 403:** auth failure (most likely cause: you pasted the API Key instead of the API Secret). Outpost records the failure in the post's `_outpost_posse_failures` meta; no admin notice shows it yet. **No retry** — fix the credentials first.
- **429, 502, 503, 504, network timeout:** transient. The G3.5b dispatcher retries up to two more times.
- **2xx with no `broadcast.id`:** treated as failure with `retryable=false`.

## OAuth v4

Kit's v4 API supports OAuth 2.0, but Outpost has no Kit OAuth provider: `includes/oauth/providers/` contains Notion, Oura, Polar, Ravelry, Ride with GPS, and WHOOP only. A "Connect Kit" button isn't available yet. The v3 API secret on the API Keys tab is the only way to connect Kit today.

## What this is NOT

- **A subscriber manager.** Outpost doesn't manage Kit subscribers, tags, or segments.
- **A v4 API integration.** This adapter uses v3 exclusively. v4 broadcasts use a different request shape and OAuth-based auth.

## Reference

- API endpoint: `POST https://api.convertkit.com/v3/broadcasts`
- Auth: `api_secret` field in JSON body (NOT an HTTP header)
- Adapter class: `Outpost_POSSE_Destination_Kit` (`includes/posse/destinations/class-outpost-posse-destination-kit.php`)
- Settings option storage: `outpost_settings_api_keys` (G3.5d API Keys tab)
