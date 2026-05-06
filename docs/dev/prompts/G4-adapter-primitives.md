---
title: "G4 — Adapter primitives v1"
branch: phase-g/g4-adapter-primitives
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G4 — Adapter primitives v1

## Scope

Ship two sibling primitives that future Phase G adapters consume: a generic Open Graph + JSON-LD inbound extractor, and a multi-source composite enrichment primitive. Refactor the existing Apple Music + iTunes Lookup adapter from Phase F to demonstrate the composite primitive in production. Both primitives become the foundation for G10 (scripture cluster fallback chains) and G15 (Snipd refactor).

## Files to create or modify

Create:

- `outpost/includes/adapters/primitives/class-og-inbound.php` — `Outpost\Adapters\Primitives\Og_Inbound`
- `outpost/includes/adapters/primitives/class-composite-inbound.php` — `Outpost\Adapters\Primitives\Composite_Inbound`
- `outpost/includes/adapters/primitives/interface-source-extractor.php` — `Outpost\Adapters\Primitives\Source_Extractor` (interface for category-specific extractors)
- `outpost/includes/adapters/primitives/extractors/class-recipe-extractor.php` — JSON-LD `Recipe` schema handler
- `outpost/includes/adapters/primitives/extractors/class-event-extractor.php` — JSON-LD `Event` schema handler
- `outpost/includes/adapters/primitives/extractors/class-article-extractor.php` — JSON-LD `Article` and `NewsArticle` schema handler
- `outpost/includes/adapters/primitives/extractors/class-book-extractor.php` — JSON-LD `Book` schema handler
- `outpost/includes/adapters/primitives/extractors/class-restaurant-extractor.php` — JSON-LD `Restaurant` and `FoodEstablishment`
- `outpost/tests/integration/adapters/primitives/test-og-inbound.php`
- `outpost/tests/integration/adapters/primitives/test-composite-inbound.php`
- `outpost/tests/fixtures/og-inbound/` — fixture HTML files (one per category extractor)
- `outpost/docs/adapters/_primitives.md`

Modify:

- `outpost/includes/adapters/class-apple-music-adapter.php` — refactor to use `Composite_Inbound` for the iTunes Lookup enrichment (this is the demonstration case)
- `outpost/tests/integration/adapters/test-apple-music-adapter.php` — update mocks to match the new composite call shape

## Design decisions locked

### Og_Inbound

1. **Response shape** is a fixed associative array:
   ```php
   [
       'title'           => string,
       'description'     => string,
       'image'           => string|null,        // resolved absolute URL or null
       'site_name'       => string|null,
       'type'            => string|null,        // og:type
       'schema_org_data' => array,              // first matching JSON-LD block, decoded; empty array if none
       'raw_meta'        => array,              // all og:* and twitter:* meta tags as flat key-value
       'fetched_at'      => string,             // ISO 8601
       'source_url'      => string,             // the URL that was fetched (after redirect resolution)
   ]
   ```
2. **Category extractors register via filter** `outpost_og_extractors` returning an array keyed by Schema.org type. Built-in extractors register themselves on `plugins_loaded`. Third-party adapters can register additional extractors via the same filter.
3. **Failure returns `WP_Error`** with codes `outpost_og_fetch_failed`, `outpost_og_parse_failed`, `outpost_og_invalid_url`. Never throws.
4. **Caching is a 1-hour WP transient** keyed `outpost_og_inbound_` + `md5(strtolower($url))`. Filterable TTL via `outpost_og_inbound_cache_ttl`.
5. **No robots.txt check.** Outpost only fetches URLs the user has explicitly shared with intent to syndicate. Justify in PHPDoc on the fetch method.
6. **User-Agent** is `Outpost/{plugin_version} (+https://github.com/courtneyr-dev/outpost)`. Filterable via `outpost_og_inbound_user_agent`.
7. **Redirect handling:** follow up to 5 redirects; record final URL in `source_url`; record original URL in `raw_meta['_original_url']`.
8. **Timeout:** 10 seconds connect + read. Filterable.
9. **HTML parsing uses DOMDocument with libxml errors suppressed.** Schema.org JSON-LD extraction prefers the first valid `application/ld+json` block matching a registered extractor's `@type`.

### Composite_Inbound

1. **Source list spec** is an array of source descriptors:
   ```php
   [
       [
           'id'       => 'snipd_api',     // free-form identifier for cache key signature
           'role'     => 'primary',       // 'primary' | 'fallback' | 'enrich'
           'callback' => callable,        // returns array|WP_Error
           'timeout'  => 5,               // seconds; default 5
       ],
       // ...
   ]
   ```
2. **Merge strategy enum:** `'first_non_null'` (default for primary→fallback), `'deep_merge'` (default for primary+enrich), `'user_callback'` (calls a user-provided merger receiving an array of per-source results).
3. **Execution order:** primaries run first, sequentially in array order, until one succeeds. Fallbacks run only if all primaries fail. Enrichers run in parallel with the successful primary using `wp_remote_request` with non-blocking pattern; enricher failures are logged at debug level and swallowed.
4. **Total wall-clock cap:** 15 seconds per call. If the cap is hit, return whatever has succeeded so far (degrade gracefully).
5. **Cache key** is `outpost_composite_` + `md5($url . '|' . wp_json_encode($source_signatures))` where `$source_signatures` is the sorted list of source `id` values. TTL 1 hour, filterable via `outpost_composite_cache_ttl`.
6. **Failure semantics:** returns `WP_Error('outpost_composite_all_failed', ...)` if no primary or fallback succeeded. Returns merged array if any success, with a `_composite_meta` key documenting which sources succeeded, which failed, and elapsed time per source.
7. **Idempotency:** identical (url, source_list_signature) calls within the cache TTL return cached result without re-fetching. Pass `force_refresh => true` in third argument to bypass cache.
8. **No automatic retries.** Sources are responsible for their own retry logic. Composite primitive does not retry failed sources within a single call.

### Apple Music refactor target

1. The existing F-phase Apple Music adapter's iTunes Lookup enrichment is the reference composite case.
2. After refactor, the adapter calls `Composite_Inbound::fetch($apple_music_url, [primary => apple_music_api, enrich => itunes_lookup])` and merges album art at higher resolution from the iTunes Lookup result into the Apple Music API result.
3. Existing public method signatures on the adapter must remain backward compatible. Only the internal implementation changes.
4. If existing F-phase test mocks need updating, update them; do not rewrite the entire test file.

## Implementation outline

### Og_Inbound

- Public method: `Og_Inbound::fetch( string $url, array $args = [] ): array|WP_Error`
- Public method: `Og_Inbound::register_extractor( Source_Extractor $extractor ): void`
- Internal: `fetch_html()` → `parse_meta()` → `parse_jsonld()` → `dispatch_extractors()` → `assemble_response()`
- Cache check happens before `fetch_html()`; cache write happens after `assemble_response()` succeeds.

### Composite_Inbound

- Public method: `Composite_Inbound::fetch( string $url, array $sources, array $args = [] ): array|WP_Error`
- Public method: `Composite_Inbound::register_merge_strategy( string $name, callable $merger ): void`
- Internal: `validate_source_list()` → `check_cache()` → `run_primaries()` → if success, `run_enrichers_parallel()` → `apply_merge_strategy()` → `cache_write()` → return
- If primaries all fail, `run_fallbacks()` before giving up.

### Source_Extractor interface

```php
interface Source_Extractor {
    public function supported_types(): array;        // ['Recipe', 'NutritionInformation']
    public function priority(): int;                  // higher wins on conflict; default 10
    public function extract( array $jsonld_block, string $url ): array;  // category-specific fields
}
```

## Tests

### Unit / integration coverage required

- **Og_Inbound:**
  - Successful fetch with rich OG: assert all response keys populated.
  - Successful fetch with minimal OG (only og:title): assert other keys are null/empty as appropriate.
  - 404: assert `WP_Error` with code `outpost_og_fetch_failed`.
  - Malformed HTML: assert no fatal; returns response with empty fields.
  - Multiple JSON-LD blocks: assert first matching extractor's type wins; assert priority ordering when types overlap.
  - Cache hit: assert second identical call does not fire HTTP request (use `wp_remote_request` mock count).
  - Each registered extractor (Recipe, Event, Article, Book, Restaurant) has a fixture and a passing test.
- **Composite_Inbound:**
  - Primary success: enrichers parallel, merged result returned.
  - Primary fail → fallback success: assert fallback ran, assert primary's failure logged.
  - All primaries + fallbacks fail: assert `WP_Error`.
  - Enricher fail: assert primary result returned without enrich data, assert error logged at debug level.
  - Wall-clock cap hit: assert partial result returned with `_composite_meta` showing which sources timed out.
  - Cache hit: assert no callbacks fired on second identical call.
  - Force refresh: assert callbacks fire even with cache present.
- **Apple Music refactor:**
  - Existing tests pass after refactor (regression check).
  - New test asserts iTunes Lookup enrichment merges album art correctly.

### wp-env stub pickup

Pick up the following stubs from the 80 skipped integration test list (per Phase F handoff). If exact stub names differ, adapt:

- `test_og_inbound_fetches_from_external_url`
- `test_composite_inbound_falls_back_on_primary_failure`
- `test_apple_music_uses_composite_primitive`

Wire them up with the wp-env Docker network configuration so external HTTP calls go through the mock server. Document the wiring in `outpost/tests/integration/README.md` if not already documented.

## Acceptance criteria

- [ ] Both primitive classes implemented per the locked design above.
- [ ] All five built-in extractors implemented.
- [ ] Apple Music adapter refactored to use Composite_Inbound; existing tests still pass.
- [ ] Unit + integration tests added; full test suite passes locally.
- [ ] §5 audit lint passes locally and in CI.
- [ ] Docs page `outpost/docs/adapters/_primitives.md` written, covering both primitives, the Source_Extractor interface, and how third-party adapters register custom extractors and merge strategies.
- [ ] PHPDoc on every public method, including `@since`, `@param`, `@return`.
- [ ] No forbidden words in commit messages, PR description, code comments, or docs.

## PR description template

```
### Phase G — G4 — Adapter primitives v1

Implements two sibling primitives shared across Phase G adapters:

- `Outpost\Adapters\Primitives\Og_Inbound` — generic OG + JSON-LD extractor with category-specific extractors registered via filter.
- `Outpost\Adapters\Primitives\Composite_Inbound` — multi-source enrichment with declared source list, merge strategy, per-source timeout, partial-fail handling, and cache-key composition.

Refactors the existing Apple Music adapter to use Composite_Inbound for iTunes Lookup enrichment as a real-world reference.

### Catalog reference

Phase G catalog §11, G4 entry. Detailed prompt: `outpost/docs/dev/prompts/G4-adapter-primitives.md`.

### Locked design decisions

See prompt §"Design decisions locked". Notable:

- Composite primary→fallback chain runs sequentially; enrichers run in parallel with successful primary; failed enrichers logged at debug level and swallowed.
- Total wall-clock cap of 15s per composite call; partial results returned on timeout.
- No automatic retries inside primitives; sources own their retry logic.

### Test plan

- 38 new unit/integration tests across both primitives + 5 extractors + Apple Music regression.
- 3 wp-env stubs picked up from the 80-stub backlog.
- §5 audit lint clean locally and on CI.

### Merge order

Independent. Open this PR first; G10 and G15 are stacked on this branch.
```

## Open items

None. All design decisions locked above.

If during implementation Claude Code discovers a constraint that contradicts a decision above (e.g., existing F-phase code uses a different cache-key format), it must:

1. Match existing convention rather than the spec above.
2. Document the deviation in the PR description under a new "Conventions adopted from existing code" section.
3. Note the deviation in `.overnight-progress.md`.
