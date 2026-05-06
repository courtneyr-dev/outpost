---
title: "G13a — Pretalx hosted SaaS og_tags"
branch: phase-g/g13a-pretalx-hosted
base: main
depends: []
phase: G
status: ready-for-implementation
splits-from: G13
---

# G13a — Pretalx hosted SaaS og_tags (foundation-free)

## Why this exists

The original G13 prompt covered Sessionize (per-event endpoint URLs require settings UI) and Pretalx multi-instance (multiple instances require settings UI). Both blocked on settings-UI scaffolding. This split ships single-instance Pretalx hosted SaaS at `pretalx.com/{event}/talk/{id}` as a pure og_tags adapter — no settings, no auth, just URL-pattern detection. Sessionize and self-hosted Pretalx wait for G3.5 settings-UI foundation.

## Scope

A new og_tags-style source adapter for `pretalx.com` hosted talks and schedules. Detects URL patterns, fetches OG meta, returns structured content.

## Files to create

Match the F17 batch pattern.

- `includes/sources/class-outpost-source-pretalx.php`
- `tests/unit/SourcePretalxTest.php` or matching F-phase test layout
- `docs/adapters/pretalx.md`

Modify the source dispatcher to register the new source.

## Design decisions locked

1. **URL patterns recognized:**
   - `pretalx.com/{event}/talk/{id}` → talk page → Post Kind `quote` (talk title and abstract).
   - `pretalx.com/{event}/talk/{id}/` → trailing slash variant; same.
   - `pretalx.com/{event}/schedule` → event schedule → Post Kind `bookmark`.
   - `pretalx.com/{event}/speaker/{id}` → speaker page → Post Kind `bookmark`.
   - Other paths under `pretalx.com` → fall through, do not handle.
2. **Self-hosted Pretalx instances NOT handled in this PR.** A self-hosted instance lives at a custom domain (e.g., `cfp.example.com`) and requires user configuration. The docs page says explicitly: "G13a covers pretalx.com hosted only. Self-hosted Pretalx support is G13b after settings-UI foundation lands."
3. **Sessionize NOT handled in this PR.** Sessionize requires per-event endpoint URLs configured in settings. Same constraint as self-hosted Pretalx. Wait for G13c.
4. **OG behavior:** Pretalx serves clean OG tags on talk and schedule pages. Use them as-is.
5. **Post Format suggestion:** `aside` for talk/speaker captures.
6. **No API call.** Pretalx has a REST API but using it would require either a token (settings-UI gap) or unauthenticated calls that are slower and rate-limited. og_tags is the simpler v1.
7. **Open-source alternative call-out:** the docs page recommends Pretalx for self-hosters as the open alternative to Sessionize. Reference the alternatives doc page from G16 (#38) once that PR merges.
8. **Naming conventions:** F-phase kebab-case + `Outpost_` prefix. No PHP namespaces.

## Tests

- URL detection: talk URL matches; schedule URL matches; speaker URL matches; non-pretalx URL rejected; self-hosted-pattern URL not handled (returns false from detect, lets other sources try).
- Talk fetch: OG meta returned with title + description + image.
- Schedule fetch: returns event-level OG meta.
- 404: clean error.

## Acceptance criteria

- [ ] Adapter created and registered.
- [ ] All four URL patterns tested.
- [ ] OG fetch tested against fixtures.
- [ ] §5 audit lint passes.
- [ ] No forbidden words.
- [ ] Docs page notes the deferred G13b (self-hosted) and G13c (Sessionize) follow-ups.
- [ ] Docs page links to G16 alternatives page.
- [ ] Diff under 300 lines.

## PR description template

```
### Phase G — G13a — Pretalx hosted SaaS og_tags

Single-instance Pretalx adapter for the pretalx.com hosted SaaS, using og_tags. Foundation-free.

Self-hosted Pretalx (G13b) and Sessionize (G13c) wait on G3.5 settings-UI foundation. Mobilizon assumed covered by F-phase ActivityPub generic adapter (verified during pre-flight check).

### Catalog reference

Phase G expansion catalog §1. Detailed prompt: `docs/dev/prompts/G13a-pretalx-hosted.md`.

### Test plan

6+ tests covering URL detection across four patterns and OG fetch.

### Merge order

Independent. Bases on main.
```

## Open items

- Verify F-phase ActivityPub generic adapter handles Mobilizon. If it doesn't, log to `.morning-questions.md` (or `.overnight-questions.md` if running overnight) and continue. Do not add Mobilizon to this PR's scope.
