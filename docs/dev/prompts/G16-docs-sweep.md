---
title: "G16 — Docs sweep + open-source alternatives doc page"
branch: phase-g/g16-docs
base: main
depends: [G4, G5, G6, G7, G8, G9, G10, G11, G12, G13, G14, G15]
phase: G
status: ready-for-implementation
---

# G16 — Docs sweep + open-source alternatives doc page

## Scope

Final Phase G PR. New normative docs page documenting Outpost's open-source-first principle and recommended alternatives. WordPress.org plugin readme.txt updated with the Phase G platform list. Per-adapter docs pages get cross-links to the alternatives page where relevant.

This PR opens last because it references the other PRs' adapter docs. Branch from `main` after every other Phase G PR is open.

## Files to create or modify

Create:

- `outpost/docs/concepts/why-we-recommend-these-platforms.md` — the normative docs page
- `outpost/docs/concepts/headless-send-vs-posse.md` — already created in G7 if Claude Code put it there; if not, create here

Modify:

- `outpost/readme.txt` — WP.org plugin readme; add Phase G platform list under "Supported platforms"; bump "Tested up to" if needed; bump version
- `outpost/outpost.php` — bump plugin version header
- All Phase G adapter docs pages (created in G4-G15) — add the appropriate alternatives call-out

## Design decisions locked

### Alternatives page content

1. **Single normative document** stating Outpost's open-source-first principle.
2. **Recommended pairings table.** Each row: closed-source/VC-owned platform → open-source alternative → why we recommend.
   - RateBeer → **Brewver** (RateBeer shut down Feb 2025 by ZX Ventures/AB InBev; Brewver is community-led successor)
   - Mountain Project → **OpenBeta** (MP API deprecated 2020 by onX; OpenBeta is CC0 open data)
   - Eventbrite → **Mobilizon** for community events, **Pretix** for ticketed events
   - Sessionize → **Pretalx** for self-hosters
   - Mailchimp → **Listmonk** for self-hosters, or **Buttondown** for free-tier hosted with developer-friendly API
   - Thingiverse → **Manyfold** for self-hosters with full library control
   - Yelp → no recommended alternative; Yelp's monopoly position is structural
   - YouVersion → no recommended alternative; api.bible is closed but provides 2500+ versions under open partnership terms
3. **Tone:** principled but not preachy. Acknowledge that closed platforms have their place; explain why open alternatives matter for IndieWeb users specifically.
4. **Cross-linked.** Every Phase G adapter docs page that has a recommended alternative links to this page in a "See also: open-source alternatives" footer.

### readme.txt update

5. **Section: "Supported platforms"** organized by category, listing all Phase F + Phase G platforms with checkmarks for inbound/outbound/headless-send.
6. **Stable tag bumped** to next minor (likely 0.2.0 if Phase G is a minor release; check git tags and follow existing semver pattern).
7. **Tested up to:** current WordPress version per F-phase pattern.
8. **Changelog entry** for Phase G summarizing the platform additions and the new primitives.

### Version bump

9. **outpost.php header** version updated to match readme.txt stable tag.
10. **CLAUDE.md not modified** (decision lock from runbook §2).

## Implementation outline

- Write the normative docs page from scratch using the catalog §0 "Cross-cutting findings" as source material.
- For each Phase G adapter docs page (15 in total across G4-G15), append a "See also: open-source alternatives" section with link.
- Update readme.txt structurally to add Phase G platforms; preserve existing F-phase content.
- Bump version in outpost.php and readme.txt to match.

## Tests

- readme.txt validates against WP.org's readme validator (run via `wp i18n make-pot` or whatever tool the F-phase CI uses).
- All cross-links from adapter docs to alternatives page resolve (run a markdown-link-check tool if available; else manual grep).
- Stable tag matches outpost.php header.

## Acceptance criteria

- [ ] Alternatives page written; clear normative tone; not preachy.
- [ ] All applicable adapter docs pages have cross-link footer.
- [ ] readme.txt validates.
- [ ] Version bumped consistently.
- [ ] §5 audit lint passes (this PR is mostly docs, but lint should still pass).
- [ ] No forbidden words.

## PR description template

```
### Phase G — G16 — Docs sweep + alternatives page

Final Phase G PR. New normative docs page documenting Outpost's open-source-first principle. WP.org readme updated with Phase G platform list. Per-adapter docs cross-linked to the alternatives page.

Catalog reference: §11 G16 entry, §0 cross-cutting findings (which is the source material for the alternatives page). Detailed prompt: `outpost/docs/dev/prompts/G16-docs-sweep.md`.

### Phase G decision 3

Per Phase G decision 3 (open-source alternatives documented as normative recommendations), this PR delivers the alternatives page with the recommended pairings table.

### Merge order

Open last among Phase G PRs. Can merge in any order relative to other Phase G PRs once they're in. If any of G4-G15 land changes that affect their docs pages, this PR may need a follow-up commit to incorporate those changes.
```

## Open items

- If any Phase G adapter PR (G4-G15) is blocked or skipped overnight, that adapter's docs page won't exist when G16 runs. Action: G16 references the alternatives page for the platforms that DID get adapter docs created. The skipped adapters' alternatives entries can still appear in the alternatives page itself (as recommendations for users who haven't enabled those adapters yet). Document the skipped adapters in the PR description.
