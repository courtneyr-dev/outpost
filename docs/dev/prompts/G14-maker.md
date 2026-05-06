---
title: "G14 — Maker cluster"
branch: phase-g/g14-maker
base: main
depends: []
phase: G
status: ready-for-implementation
---

# G14 — Maker cluster

## Scope

Add Ravelry (knit/crochet patterns and projects, OAuth + rich metadata), iFixit (CC-BY-NC-SA repair guides, no-auth read), and Adafruit Learning System (RSS feeds for tutorials and projects). Hackaday.com is a WordPress site — assumed covered by core WP support. Hackaday.io project hosting is a Phase H candidate (not in this PR).

## Files to create or modify

Create:

- `outpost/includes/adapters/class-ravelry-adapter.php`
- `outpost/includes/adapters/class-ifixit-adapter.php`
- `outpost/includes/adapters/class-adafruit-learning-adapter.php`
- One test file per adapter
- One docs page per adapter

## Design decisions locked

### Ravelry

1. **Auth: OAuth 2.0** preferred (Ravelry supports both 1.0a and 2.0; 2.0 is the modern path). Settings page has "Connect Ravelry" button.
2. **Inbound:**
   - Pattern URL: `ravelry.com/patterns/library/{slug}` → fetch pattern, render with rich metadata (designer, gauge, yardage, fiber type, needles, free/paid status).
   - Project URL: `ravelry.com/projects/{username}/{slug}` → fetch project with photos, notes, finished date.
3. **Outbound:**
   - WP project posts can syndicate to Ravelry as new project entries (pattern reference + photos + notes).
   - Outbound is opt-in per post via sidebar.
4. **Photo handling:** Project photos imported to WP media library on inbound capture.
5. **Post Kind suggestion:**
   - Project (with photos) → `photo` if single, `note` with custom Post Format `gallery` if multiple.
   - Pattern reference → `bookmark`.
6. **Metadata as post meta:** All Ravelry-specific fields (gauge, yardage, fiber, needles, designer) stored as post meta with `outpost_ravelry_*` keys.

### iFixit

7. **No auth required for read.** Public API at `ifixit.com/api/2.0/`.
8. **Inbound:** Guide URL or wiki URL → fetch guide content via `GET /api/2.0/guides/{guideid}`. Renders as long-form quote or bookmark.
9. **License: CC-BY-NC-SA.** Attribution rendered automatically; per-post override available (with warning that override may violate license).
10. **Post Kind suggestion:** `bookmark`.
11. **Outbound deferred to Phase H.** iFixit supports authed POST for wiki edits; not in v1.

### Adafruit Learning System

12. **RSS-only.** No API. Feed at `learn.adafruit.com/rss.xml` (master feed) or category-specific feeds.
13. **Inbound:** User pastes guide URL; adapter fetches RSS, finds matching item, falls back to OG if missing.
14. **License:** Adafruit Learning is mostly Creative Commons; license per-guide. Adapter records license from RSS if present, OG if not, falls through if neither.
15. **Post Kind suggestion:** `bookmark`.

### Shared

16. **All three are inbound-primary.** Ravelry has limited outbound (project posting); iFixit and Adafruit are inbound-only in v1.

## Implementation outline

- Ravelry: standard OAuth 2.0 + REST client; rich metadata extraction.
- iFixit: thin REST client; license attribution rendering.
- Adafruit: extends RSS-with-OG-fallback base from G6 (or F-phase if it exists).

## Tests

- Ravelry: pattern, project, and outbound project creation against mocked API.
- Ravelry photo import: project photos correctly downloaded to media library.
- iFixit: guide fetch + license attribution rendering.
- iFixit license override: warning surfaced.
- Adafruit: RSS happy path + OG fallback when item missing from feed.

### wp-env stubs

- `test_ravelry_project_capture`
- `test_ifixit_guide_with_attribution`
- `test_adafruit_rss_capture`

## Acceptance criteria

- [ ] All three adapters work against real-world test URLs.
- [ ] Ravelry rich metadata stored correctly.
- [ ] iFixit attribution rendered; override warning present.
- [ ] Adafruit RSS + OG fallback both tested.
- [ ] Tests pass.
- [ ] §5 audit lint passes.
- [ ] Three docs pages written.

## PR description template

```
### Phase G — G14 — Maker cluster

Adds Ravelry (OAuth + rich pattern/project metadata, opt-in outbound), iFixit (no-auth read, CC-BY-NC-SA attribution), Adafruit Learning System (RSS + OG fallback).

Catalog reference: §11 G14 entry, §4 Maker table. Detailed prompt: `outpost/docs/dev/prompts/G14-maker.md`.

### Test plan

20+ tests across three platforms. 3 wp-env stubs.

### Merge order

Independent.
```

## Open items

None. Hackaday.io and Instructables RSS deferred to Phase H per catalog Tier 2.
