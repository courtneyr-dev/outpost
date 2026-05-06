---
title: "G6 — Newsletter RSS inbound cluster"
branch: phase-g/g6-newsletter-inbound
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G6 — Newsletter RSS inbound cluster

## Scope

Add Bear Blog and Mataroa as inbound capture sources via RSS, sharing the adapter shape with Substack and Ghost from the initial Phase G catalog. Single shared `Rss_With_Og_Fallback_Adapter_Base` if not already present from F-phase.

## Files to create or modify

Create:

- `outpost/includes/adapters/inbound/class-rss-with-og-fallback-base.php` (only if F-phase doesn't already have an equivalent)
- `outpost/includes/adapters/inbound/class-bear-blog-adapter.php` — `Outpost\Adapters\Inbound\Bear_Blog_Adapter`
- `outpost/includes/adapters/inbound/class-mataroa-adapter.php` — `Outpost\Adapters\Inbound\Mataroa_Adapter`
- `outpost/tests/integration/adapters/inbound/test-bear-blog-adapter.php`
- `outpost/tests/integration/adapters/inbound/test-mataroa-adapter.php`
- `outpost/docs/adapters/bear-blog.md`
- `outpost/docs/adapters/mataroa.md`

Modify:

- Whatever class registers inbound adapters with the inbound dispatcher (matches F-phase Substack/Ghost registration pattern).

## Design decisions locked

1. **URL detection patterns:**
   - Bear Blog: `*.bearblog.dev` or self-hosted Bear (configurable subdomain pattern via filter `outpost_bear_blog_domain_patterns`).
   - Mataroa: `*.mataroa.blog` or self-hosted Mataroa (similar filter `outpost_mataroa_domain_patterns`).
2. **Primary path: RSS.** Each platform exposes a per-blog RSS feed at `/feed/` (Bear) or `/rss/` (Mataroa). Adapter fetches RSS, finds the matching item by URL, returns structured content.
3. **Fallback path: OG.** When RSS doesn't have the item (e.g., user shared a very recent post not yet in cached feed), fall back to `Og_Inbound`. This is NOT a composite primitive use; it's a sequential try-rss-then-og attempt because RSS isn't always present and a full composite is overkill.
4. **Post Kind suggestion:** `bookmark` for inbound RSS captures. Composer can override to `quote` if user is excerpting.
5. **Cache RSS feed for 1 hour** per blog. Item-level lookup hits the cached feed.

## Implementation outline

- Each adapter implements `detect_url($url): bool`, `fetch_metadata($url): array|WP_Error`.
- Shared base handles RSS fetch + parsing + item lookup + OG fallback wiring.
- Existing F-phase Substack and Ghost adapters refactor to use shared base if cleanup is straightforward; defer if not.

## Tests

- Bear Blog: known post URL returns title, body, published_at, author.
- Mataroa: same shape.
- RSS miss → OG fallback fires; returns OG-derived metadata.
- Self-hosted instance with custom domain works after filter registration.

### wp-env stubs

- `test_bear_blog_rss_capture`
- `test_mataroa_og_fallback_when_rss_missing`

## Acceptance criteria

- [ ] Both adapters fetch metadata correctly from real-world test URLs.
- [ ] Self-hosted domain support via filters works.
- [ ] Tests pass.
- [ ] §5 audit lint passes.
- [ ] Docs pages written.

## PR description template

```
### Phase G — G6 — Newsletter RSS inbound cluster

Adds Bear Blog and Mataroa inbound capture via RSS with OG fallback. Shared adapter shape with existing F-phase Substack and Ghost.

Catalog reference: §11 G6 entry. Detailed prompt: `outpost/docs/dev/prompts/G6-newsletter-inbound.md`.

### Test plan

8+ tests; 2 wp-env stubs picked up.

### Merge order

Independent.
```

## Open items

None.
