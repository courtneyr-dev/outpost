# Beehiiv POSSE destination

Outpost's Beehiiv destination publishes a fan-out copy of each WordPress post into a Beehiiv publication on publish. The WordPress post stays canonical; the Beehiiv copy carries a "originally appeared on" link back to the source.

Shipped in G5a (Phase G).

## Setup

1. **Get an API key.** In Beehiiv, go to Settings → Integrations → API. Generate a key.
2. **Find your publication ID.** Same page, the `pub_…` identifier.
3. **Configure Outpost.** WP admin → Outpost → Settings → API Keys. Fill in:
    - `Syndicate to Beehiiv` (checkbox)
    - `Beehiiv API key` (stored encrypted at rest)
    - `Beehiiv publication ID`
4. **Mark posts to syndicate.** In the Gutenberg post sidebar, toggle Beehiiv on for the posts that should fan out. The first scheduled dispatch fires 30 seconds after publish (G3.5b dispatcher).

## What gets syndicated

- **Subject:** the WordPress post title.
- **Body:** the post's block content rendered through `do_blocks()` + `wpautop()` to HTML. Appended at the end: a small paragraph linking back to the WordPress permalink.
- **Status:** `confirmed` so the post lands in Beehiiv's auto-publish flow rather than as a draft you'd have to confirm manually.

## Canonical link

Beehiiv's posts API has no native `canonical_url` field. Outpost appends a "This post originally appeared on …" paragraph to the body so search engines and feed readers find their way back to the canonical WordPress URL. If Beehiiv adds a canonical-URL field later, the adapter will move to that field and drop the appended paragraph.

## Failure handling

- **401 / 403:** auth failure. Outpost marks the dispatch failed and surfaces an admin notice. **No retry** — fix the credentials first.
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
