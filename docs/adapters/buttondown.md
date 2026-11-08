# Buttondown POSSE destination

Outpost's Buttondown destination publishes a fan-out copy of each WordPress post as a Buttondown email on publish. The WordPress post stays canonical; Buttondown's native `canonical_url` field carries the link back.

Shipped in G5a (Phase G).

## Setup

1. **Set the encryption key.** Outpost encrypts the API key at rest, and the API Keys tab hides its form, showing "Outpost encryption key is not configured", until a key exists. Add the constant to `wp-config.php`; it must be 32 raw bytes, so wrap the base64 value in `base64_decode()`:

    ```php
    // Generate the value once with: php -r "echo base64_encode(random_bytes(32));"
    define( 'OUTPOST_ENCRYPTION_KEY', base64_decode( 'paste-the-output-here' ) );
    ```

    See [Encryption key](../concepts/encryption-key.md) for the database fallback.
2. **Get an API key.** In Buttondown, go to Settings → API. Generate a token.
3. **Configure Outpost.** WP admin → Outpost → Settings → API Keys. Fill in:
    - `Syndicate to Buttondown` (checkbox)
    - `Buttondown API key` (stored encrypted at rest)
    - `Send as draft for manual review` (optional checkbox)
4. **Mark posts to syndicate.** The block editor has no destination selector yet. Set the `_outpost_posse_targets` post meta to a list of destination ids that includes `buttondown`, before the post is published. The REST route is `POST /wp-json/wp/v2/posts/<id>`, and the request needs a user with `edit_post` on that post. Set `WP_USER` to your username and `WP_APP_PASSWORD` to an application password (Users → Profile → Application Passwords) first:

    ```bash
    curl -X POST https://example.com/wp-json/wp/v2/posts/123 \
      -u "$WP_USER:$WP_APP_PASSWORD" \
      -H 'Content-Type: application/json' \
      -d '{"meta":{"_outpost_posse_targets":["buttondown"]}}'
    ```

    WP-CLI works too: `wp post meta update 123 _outpost_posse_targets '["buttondown"]' --format=json`. The meta is registered for the `post` post type only, and the post's author needs `publish_posts`. When the post moves into `publish`, the dispatcher schedules the first attempt 30 seconds later. Edits to a post that's already published don't dispatch again.

## What gets syndicated

- **Subject:** the WordPress post title.
- **Body:** the post's block content rendered through `do_blocks()` + `wpautop()`, then converted to markdown by the shared content transformer. Buttondown emails render markdown natively.
- **`canonical_url`:** the WordPress permalink, set on Buttondown's dedicated field. **No appended paragraph in the body** — Buttondown's native canonical-URL handling is preferred over an inline footer.
- **Status:** defaults to `about_to_send` (immediate dispatch). When `Send as draft for manual review` is enabled, status becomes `draft` and the email waits in Buttondown for you to push the send button.

## Canonical link

Buttondown is the one G5 destination with native `canonical_url` support. Outpost uses the field; nothing extra is appended to the email body. Result: the email reader sees clean content, and search engines / RSS / mf2 consumers parsing the Buttondown archive still find the WordPress URL as canonical.

## Auth scheme — the `Token` (not `Bearer`) gotcha

Buttondown uses `Authorization: Token …` rather than the more common `Authorization: Bearer …`. The adapter builds the header explicitly — copy-pasting an auth helper from a Bearer-shaped adapter will land 401s here. Tests assert the exact header value.

## Failure handling

- **401 / 403:** auth failure. Outpost records the failure in the post's `_outpost_posse_failures` meta; no admin notice shows it yet. **No retry** — fix the credentials first.
- **429, 502, 503, 504, network timeout:** transient. The G3.5b dispatcher retries up to two more times.
- **2xx with no `web_url` / `absolute_url` / `permalink` in the response:** treated as failure with `retryable=false`.

## What this is NOT

- **A list manager.** Outpost doesn't manage Buttondown subscribers, segments, or tags. The fan-out lands as a single email; Buttondown's own delivery settings decide who receives it.

## Reference

- API endpoint: `POST https://api.buttondown.email/v1/emails`
- Auth: `Authorization: Token {api_key}`
- Adapter class: `Outpost_POSSE_Destination_Buttondown` (`includes/posse/destinations/class-outpost-posse-destination-buttondown.php`)
- Settings option storage: `outpost_settings_api_keys` (G3.5d API Keys tab)
