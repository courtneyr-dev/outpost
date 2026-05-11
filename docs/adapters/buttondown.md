# Buttondown POSSE destination

Outpost's Buttondown destination publishes a fan-out copy of each WordPress post as a Buttondown email on publish. The WordPress post stays canonical; Buttondown's native `canonical_url` field carries the link back.

Shipped in G5a (Phase G).

## Setup

1. **Get an API key.** In Buttondown, go to Settings → API. Generate a token.
2. **Configure Outpost.** WP admin → Outpost → Settings → API Keys. Fill in:
    - `Syndicate to Buttondown` (checkbox)
    - `Buttondown API key` (stored encrypted at rest)
    - `Send as draft for manual review` (optional checkbox)
3. **Mark posts to syndicate.** In the Gutenberg post sidebar, toggle Buttondown on for the posts that should fan out. The first scheduled dispatch fires 30 seconds after publish (G3.5b dispatcher).

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

- **401 / 403:** auth failure. Outpost marks the dispatch failed and surfaces an admin notice. **No retry** — fix the credentials first.
- **429, 502, 503, 504, network timeout:** transient. The G3.5b dispatcher retries up to two more times.
- **2xx with no `web_url` / `absolute_url` / `permalink` in the response:** treated as failure with `retryable=false`.

## What this is NOT

- **A list manager.** Outpost doesn't manage Buttondown subscribers, segments, or tags. The fan-out lands as a single email; Buttondown's own delivery settings decide who receives it.

## Reference

- API endpoint: `POST https://api.buttondown.email/v1/emails`
- Auth: `Authorization: Token {api_key}`
- Adapter class: `Outpost_POSSE_Destination_Buttondown` (`includes/posse/destinations/class-outpost-posse-destination-buttondown.php`)
- Settings option storage: `outpost_settings_api_keys` (G3.5d API Keys tab)
