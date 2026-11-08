# Beehiiv POSSE destination

Outpost's Beehiiv destination publishes a fan-out copy of each WordPress post into a Beehiiv publication on publish. The WordPress post stays canonical; the Beehiiv copy carries a "originally appeared on" link back to the source.

Shipped in G5a (Phase G).

## Setup

1. **Set the encryption key.** Outpost encrypts the API key at rest, and the API Keys tab hides its form, showing "Outpost encryption key is not configured", until a key exists. Add the constant to `wp-config.php`; it must be 32 raw bytes, so wrap the base64 value in `base64_decode()`:

    ```php
    // Generate the value once with: php -r "echo base64_encode(random_bytes(32));"
    define( 'OUTPOST_ENCRYPTION_KEY', base64_decode( 'paste-the-output-here' ) );
    ```

    See [Encryption key](../concepts/encryption-key.md) for the database fallback.
2. **Get an API key.** In Beehiiv, go to Settings → Integrations → API. Generate a key.
3. **Find your publication ID.** Same page, the `pub_…` identifier.
4. **Configure Outpost.** WP admin → Outpost → Settings → API Keys. Fill in:
    - `Syndicate to Beehiiv` (checkbox)
    - `Beehiiv API key` (stored encrypted at rest)
    - `Beehiiv publication ID`
5. **Mark posts to syndicate.** The block editor has no destination selector yet. Set the `_outpost_posse_targets` post meta to a list of destination ids that includes `beehiiv`, before the post is published. The REST route is `POST /wp-json/wp/v2/posts/<id>`, and the request needs a user with `edit_post` on that post. Set `WP_USER` to your username and `WP_APP_PASSWORD` to an application password (Users → Profile → Application Passwords) first:

    ```bash
    curl -X POST https://example.com/wp-json/wp/v2/posts/123 \
      -u "$WP_USER:$WP_APP_PASSWORD" \
      -H 'Content-Type: application/json' \
      -d '{"meta":{"_outpost_posse_targets":["beehiiv"]}}'
    ```

    WP-CLI works too: `wp post meta update 123 _outpost_posse_targets '["beehiiv"]' --format=json`. The meta is registered for the `post` post type only, and the post's author needs `publish_posts`. When the post moves into `publish`, the dispatcher schedules the first attempt 30 seconds later. Edits to a post that's already published don't dispatch again.

## What gets syndicated

- **Subject:** the WordPress post title.
- **Body:** the post's block content rendered through `do_blocks()` + `wpautop()` to HTML. Appended at the end: a small paragraph linking back to the WordPress permalink.
- **Status:** `confirmed` so the post lands in Beehiiv's auto-publish flow rather than as a draft you'd have to confirm manually.

## Canonical link

Beehiiv's posts API has no native `canonical_url` field. Outpost appends a "This post originally appeared on …" paragraph to the body so search engines and feed readers find their way back to the canonical WordPress URL.

## Failure handling

- **401 / 403:** auth failure. Outpost records the failure in the post's `_outpost_posse_failures` meta; no admin notice shows it yet. **No retry** — fix the credentials first.
- **429, 502, 503, 504, network timeout:** transient. The G3.5b dispatcher retries up to two more times (30s → 5min → 30min from the original schedule), then marks failed if all retries exhaust.
- **2xx with no `web_url` / `url` in the response:** treated as failure with `retryable=false`. Beehiiv's API normally returns the post URL on success; an empty URL points at an API contract change worth investigating before retry.

## What this is NOT

- **Beehiiv Send API.** The Send API is Beehiiv's paid email-blast endpoint and gates on plan tier. Outpost uses `POST /v2/publications/{id}/posts` — the standard Posts endpoint, available on every Beehiiv plan — so a 402 response should never appear here. If you see one, the auth context is wrong (e.g., key bound to a different account tier).
- **Backfill / migration.** This adapter fan-outs on publish only. It doesn't import historical posts into Beehiiv.

## Reference

- API endpoint: `POST https://api.beehiiv.com/v2/publications/{publication_id}/posts`
- Auth: `Authorization: Bearer {api_key}`
- Adapter class: `Outpost_POSSE_Destination_Beehiiv` (`includes/posse/destinations/class-outpost-posse-destination-beehiiv.php`)
- Settings option storage: `outpost_settings_api_keys` (G3.5d API Keys tab)
