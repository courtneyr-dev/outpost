# Adapter primitives (G4a)

Two sibling primitives Phase G adapters consume to avoid re-implementing fetch + parse + merge plumbing. Concrete schema extractors plug into Og_Inbound's dispatch in G4b.

## When to reach for which

- **`Outpost_Og_Inbound`** — single URL, one round-trip; returns OG + Twitter meta + first matching JSON-LD block. Use when the source emits Open Graph + Schema.org JSON-LD.
- **`Outpost_Composite_Inbound`** — multiple sources for one URL. Primaries try in order; fallbacks run if all primaries fail; enrichers run alongside the successful primary and merge in. Failed enrichers log at debug level. Use when metadata is split across endpoints. Compose: a Composite source's callback can itself be `Og_Inbound::fetch( $url )`.

## `Outpost_Og_Inbound`

### Public surface

```php
Outpost_Og_Inbound::fetch( string $url, array $args = array() ): array|WP_Error;
Outpost_Og_Inbound::register_extractor( Outpost_Schema_Extractor $extractor ): void;
```

### Response shape

```php
[
    'title'           => 'Page Title',
    'description'     => 'Description text…',
    'image'           => 'https://example.com/img.jpg',  // resolved absolute URL or null
    'site_name'       => 'Site Name',                     // or null
    'type'            => 'article',                       // og:type or null
    'schema_org_data' => [
        // empty array when no extractor matches a JSON-LD block;
        // populated by the matching Outpost_Schema_Extractor
    ],
    'raw_meta'        => [
        'og:title' => '…',
        'twitter:image' => '…',
        '_original_url' => '…',  // pre-redirect URL
    ],
    'fetched_at'      => '2026-05-06T03:14:22+00:00',
    'source_url'      => 'https://example.com/final-after-redirects',
]
```

### Failure shape

`WP_Error` with one of:
- `outpost_og_invalid_url` — URL did not pass `wp_http_validate_url`.
- `outpost_og_fetch_failed` — non-2xx response or transport error.
- `outpost_og_parse_failed` — empty body or parse failure.

### Caching

1-hour transient keyed `outpost_og_inbound_` + `md5( strtolower($url) )`. Filter `outpost_og_inbound_cache_ttl` returns `int` seconds. Pass `force_refresh => true` in `$args` to bypass.

### Why no `robots.txt` check

Outpost only fetches URLs the user has explicitly shared with intent to syndicate. Robots.txt is a directive for crawlers; Outpost is acting on the user's behalf, not crawling. Per G4 design decision #5.

### Schema extractor interface

```php
interface Outpost_Schema_Extractor {
    public function supported_types(): array;        // ['Recipe']
    public function priority(): int;                  // higher wins on conflict; default 10
    public function extract( array $jsonld_block, string $url ): array;
}
```

Concrete extractors (Recipe, Event, Article, Book, Restaurant) ship in G4b. Third-party adapters can register additional extractors in any commit:

```php
Outpost_Og_Inbound::register_extractor( new My_Custom_Extractor() );
```

…or via the `outpost_og_extractors` filter, which receives the full extractor map keyed by Schema.org `@type`.

## `Outpost_Composite_Inbound`

### Public surface

```php
Outpost_Composite_Inbound::fetch( string $url, array $sources, array $args = array() ): array|WP_Error;
Outpost_Composite_Inbound::register_merge_strategy( string $name, callable $merger ): void;
```

### Source descriptor

```php
[
    'id'       => 'apple_music_api',  // free-form; participates in cache key
    'role'     => 'primary',           // 'primary' | 'fallback' | 'enrich'
    'callback' => callable,            // returns array|WP_Error
    'timeout'  => 5,                   // seconds; default 5
]
```

### Execution model

1. Primaries run in array order; the first to return a non-`WP_Error` array wins.
2. If all primaries fail, fallbacks run in array order.
3. Enrichers run after a primary succeeds. Failed enrichers log at debug level (`error_log` only when `WP_DEBUG`) and don't block the response.
4. A 15-second wall-clock cap covers all sources together. Hit the cap, the call returns whatever has succeeded so far (degrades gracefully). Filterable via `outpost_composite_wall_clock_cap`.

### Merge strategies

Built-in:
- `first_non_null` — primary's value wins for any key it sets; enrichers fill in only the keys the primary left null/empty. Default when no enrichers.
- `deep_merge` — recursive merge; enrichers override on string keys, append on numeric. Default when enrichers present.
- `user_callback` — pass `merger => callable` in `$args` to assemble the result yourself. Receives `[ 'primary' => array, '<enricher_id>' => array, … ]`.

Register your own:

```php
Outpost_Composite_Inbound::register_merge_strategy(
    'highest_resolution',
    static function ( array $primary, array $enrichers ): array {
        $out = $primary;
        foreach ( $enrichers as $r ) {
            if ( ( $r['image_height'] ?? 0 ) > ( $out['image_height'] ?? 0 ) ) {
                $out['image']        = $r['image'];
                $out['image_height'] = $r['image_height'];
            }
        }
        return $out;
    }
);
```

### Response envelope

On success the merged array carries a `_composite_meta` key:

```php
'_composite_meta' => [
    'sources' => [
        'apple_music_api' => [ 'id' => 'apple_music_api', 'result' => […], 'elapsed_ms' => 412 ],
        'itunes_lookup'   => [ 'id' => 'itunes_lookup',   'result' => […], 'elapsed_ms' => 287 ],
    ],
    'primary'    => 'apple_music_api',
    'elapsed_ms' => 712,
],
```

### Failure shape

`WP_Error('outpost_composite_all_failed', ...)` when no primary or fallback succeeds. The error data carries the per-source `meta` so callers can introspect which sources failed.

### Caching

1-hour transient keyed `outpost_composite_` + `md5( $url . '|' . sorted-source-ids-json )`. Identical (URL, source-set) calls return cached result. Filter `outpost_composite_cache_ttl` returns `int` seconds. Pass `force_refresh => true` to bypass.

### No automatic retries

Sources own their own retry logic. The composite primitive does not retry failed sources within a single call. This keeps the wall-clock cap predictable.

## Pattern: building a Phase G adapter on these primitives

```php
<?php
final class My_Adapter {

    public static function fetch( string $url ): array {
        $result = Outpost_Composite_Inbound::fetch(
            $url,
            array(
                array(
                    'id'       => 'official_api',
                    'role'     => 'primary',
                    'callback' => function () use ( $url ) {
                        return self::call_official_api( $url );
                    },
                ),
                array(
                    'id'       => 'og_inbound',
                    'role'     => 'fallback',
                    'callback' => function () use ( $url ) {
                        return Outpost_Og_Inbound::fetch( $url );
                    },
                ),
            )
        );

        return is_wp_error( $result ) ? array() : $result;
    }
}
```

The fallback chain gives the adapter graceful degradation: official API down, fall through to OG scraping; OG scraping fails, return an empty array and let the calling adapter render its own fallback UX.

## Reference

Spec at `docs/dev/prompts/G4-adapter-primitives.md` — locked design decisions Og_Inbound 1–9 and Composite_Inbound 1–8.

## What's NOT in G4a

Concrete schema extractors (Recipe / Event / Article / Book / Restaurant) ship in G4b — additive. Apple Music refactor demo deferred — the existing F-phase Apple Music adapter (PR #34) does not have an iTunes Lookup enrichment to refactor; see `.overnight-questions.md` Q1.
