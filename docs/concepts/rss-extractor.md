# RSS / Atom inbound extractor (F5 #6)

The 6th F5 chain extractor. Generic RSS / Atom feed primitive backed by WordPress's bundled SimplePie. Unblocks G6's full-content path for Bear Blog / Mataroa, G10b's Working Preacher and Universalis sources, and any future platform that publishes via feed.

## Two operating modes

### Mode A — `extract_from_url($url)`

The "user pasted any URL" path. Used by Source_Unknown-style flows where Outpost doesn't know the feed URL upfront.

1. Fetch the URL's HTML.
2. Look for `<link rel="alternate" type="application/rss+xml">` or `application/atom+xml`.
3. Fetch the discovered feed via `fetch_feed()`.
4. Iterate entries, find the one whose `permalink` matches the original URL.
5. Return the canonical extracted shape.

Failures (no feed link, no matching entry) return `[ 'extracted' => false, 'reason' => '...' ]` so the F5 chain can fall through to the next extractor.

### Mode B — `extract_from_feed($feed_url, $entry_guid)`

The "platform source class knows the feed" path. Used by concrete sources (Working Preacher, Universalis, etc.) after they've identified the feed URL from their own discovery logic.

1. Fetch the feed.
2. Iterate entries, find the one whose `id` (RFC 4287 `<id>` / RSS 2.0 `<guid>`) matches.
3. Return the canonical extracted shape.

## Canonical extracted shape

```php
[
    'extracted'  => true,
    'title'      => string,
    'content'    => string,         // wp_kses_post-sanitized
    'summary'    => ?string,        // null when same as content
    'author'     => ?string,
    'author_url' => ?string,
    'published'  => ?string,        // ISO 8601 UTC
    'updated'    => ?string,        // ISO 8601 UTC
    'link'       => string,         // permalink
    'categories' => string[],
    'guid'       => string,
    'icon_url'   => ?string,        // media:thumbnail or image enclosure
    'feed_url'   => string,
    'feed_title' => string,
]
```

Source classes consume this shape via their own `mapping` (the F5 source-base contract). The extractor doesn't know about h-entry properties; it produces a generic feed-entry projection that the source maps into its target shape.

## Failure shape

```php
[ 'extracted' => false, 'reason' => 'no_feed_link' ]
[ 'extracted' => false, 'reason' => 'no_matching_feed_entry' ]
[ 'extracted' => false, 'reason' => 'transport_failed' ]
[ 'extracted' => false, 'reason' => 'malformed_feed' ]
[ 'extracted' => false, 'reason' => 'entry_not_in_feed' ]
```

Source classes inspect `reason` to decide whether to retry, fall through to the next extractor, or surface the failure to the user.

## Field resolution priorities

Content (first non-empty wins):
1. SimplePie `get_content()` — prefers `<content:encoded>` then `<content>` then `<description>`.
2. SimplePie `get_description()` — fallback.

Summary: `get_description()` value when distinct from `get_content()`. When they're identical, summary is `null` (the feed has no separate summary).

Author:
1. `<author>` (RSS 2.0) → name + URL.
2. `<dc:creator>` (Dublin Core) → name only.
3. `null`.

Published date: `get_date()` returning a Unix timestamp → `gmdate('c', ...)` formatting.

Icon URL:
1. `<media:thumbnail @url>` (Yahoo Media RSS).
2. `<enclosure>` with `type` starting `image/`.
3. `null`.

## Why SimplePie + fetch_feed()

WordPress bundles SimplePie at `wp-includes/class-simplepie.php` and exposes `fetch_feed()` as the public API. No new Composer dependency, no new build pipeline, no new feed-format detection code (SimplePie auto-detects RSS 2.0, RSS 1.0/RDF, and Atom 1.0 transparently). Cache is built-in (12-hour TTL via WP's transient layer). Encoding is handled (UTF-8 conversion automatic).

We deliberately don't surface SimplePie's full feature set — only the seven-or-eight methods our canonical shape actually needs. Concrete platform sources that want the full SimplePie object can call `fetch_feed()` themselves; this primitive is for "I want the standard fields, in the standard shape, with the standard sanitization."

## Integration with the F5 chain

The original F5 plan reserved an extractor stub at `Outpost_Source_Extractor_Rss` (extending `Outpost_Source_Extractor_Base`). That base is body-string-driven — `parse($body, $recipe)` operates on already-fetched bytes. SimplePie's model is feed-URL-driven and includes its own fetch + cache.

Two paths handle the mismatch:
- `Outpost_Rss_Inbound` (THIS class) is a higher-level primitive that does its own fetching (via SimplePie/`fetch_feed`). Same pattern as G4a's `Outpost_Og_Inbound`. Concrete sources call this directly.
- The `Outpost_Source_Extractor_Rss` stub remains for the F5 dispatch model. When a concrete source declares `extractor: 'rss'`, the preview endpoint will eventually delegate to a thin wrapper around `Outpost_Rss_Inbound` rather than calling SimplePie itself.

For now, this PR ships only the primitive. The wiring of `Outpost_Source_Extractor_Rss::parse()` to delegate into the primitive is a small follow-up that concrete-source PRs (G6, G10b) will land alongside their first usage.

## Test seams

`set_feed_resolver_for_tests( callable )` and `set_page_resolver_for_tests( callable )` swap out the feed and page fetch functions. Production calls `fetch_feed()` and `wp_safe_remote_get()` respectively; tests inject closures that return prebuilt fixture objects. This avoids hitting the network in the unit suite and avoids the SimplePie dependency in the test bootstrap.

## Why no robots.txt check

Outpost only fetches URLs the user has explicitly shared with intent to syndicate. Robots.txt is for crawlers; Outpost is acting on the user's behalf for a specific URL. Same reasoning as the G4a `Outpost_Og_Inbound` primitive.
