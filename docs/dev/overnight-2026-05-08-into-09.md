# Overnight 2026-05-08 → 2026-05-09 — work log

One-file summary of the overnight queue. Read this in the morning to see what shipped, what didn't, and what's blocked.

## TL;DR

- **9 PRs opened, 0 merged** (all awaiting morning review)
- **23 stubs migrated** (cluster #7 + 4 Phase 3 clusters): 5 + 1 + 2 + 6 + 9
- **1 production-side test seam** shipped to unblock cluster #7 (gotcha #10)
- **2 reference docs** committed: readiness audit + 10-gotcha consolidation
- **Cumulative G99 progress:** clusters #1–#7 plus Phase 3 cluster #4 → **54 of ~97 stubs migrated**

## Open PRs (review order)

Suggested merge order respects stack dependencies. PRs #74 → #75 are stacked; everything else is parallel against `main`.

| # | Branch | What | Code/docs |
|---|---|---|---|
| **#73** | `phase-g/g99-readiness-audit` | Phase 1 audit — classifies every remaining cluster as ready / blocked-on-platform / blocked-on-product | docs only |
| **#74** | `phase-g/shortcut-controller-test-seam` | Gotcha #10 fix — `Outpost_Shortcut_Controller::set_payload_source_for_tests` (~15 line additive prod change) | production |
| **#75** | `phase-g/f6-shortcut-dispatch-migration` | Cluster #7 ShortcutDispatch migration (5 stubs) — STACKED on #74 | tests |
| **#76** | `phase-g/g99-gotchas-consolidated` | Phase 2 — `docs/dev/integration-test-gotchas.md` reference (10 numbered + 3 discipline rules) | docs only |
| **#77** | `phase-g/a2-routehandler-integration-migration` | Phase 3 #1 — RouteHandler (1 stub) | tests |
| **#78** | `phase-g/f3-micropub-photo-write-shape-migration` | Phase 3 #2 — MicropubPhotoWriteShape (2 stubs) | tests |
| **#79** | `phase-g/f12-syndication-capture-flow-migration` | Phase 3 #3 — SyndicationCaptureFlow (6 stubs) | tests |
| **#80** | `phase-g/f9-manual-share-integration-migration` | Phase 3 #4 — ManualShareIntegration (9 stubs) | tests |
| **#81** (this) | `phase-g/overnight-summary-2026-05-08` | This file | docs only |

## Phase-by-phase trace

### Cluster #7 attempt → gotcha #10 → seam → cluster #7 land

1. Started cluster #7 ShortcutDispatch migration as planned.
2. **Halted on first inspection** of `Outpost_Shortcut_Controller::read_json_payload()` — hard-coded `file_get_contents('php://input')` with no test seam. Per the overnight rule "if you discover a 10th platform gotcha, hard stop," documented as gotcha #10 and pivoted to docs-only Phases 1–2.
3. **User over-ride mid-way:** identified the fix as a 10-line additive prod change, not a hard stop. Authorized opening it as a separate PR.
4. **PR #74** shipped the seam (production-side `set_payload_source_for_tests` static method on the controller, with `read_json_payload` consulting it before falling back to `php://input`). Production-callers grep returns zero matches; same posture as `OUTPOST_TESTING_PWA_SHELL`.
5. **PR #75** stacked cluster #7 migration on top of #74 — 5 of 5 stubs migrated using the seam to inject JSON bodies. Stack-merge protocol: retarget #75's base to `main` BEFORE deleting #74's branch on merge, per `stacked_pr_merge_sequencing.md`.

### Phase 1 — readiness audit (PR #73)

Classified every remaining cluster:

- **Ready (5 clusters, 31 stubs):** RouteHandler · MicropubPhotoWriteShape · SyndicationCaptureFlow · ManualShareIntegration · IosShortcutConnectionFlow · AppearanceSettingsFlow
- **Blocked-on-platform (3 clusters, 13 stubs):** CompanionActivityPubPassthrough (AP plugin missing from wp-env.json) · ShortcutDispatch (gotcha #10 — RESOLVED tonight via #74) · G4bAppleMusic (gotcha #4: music.apple.com not in REWRITABLE_HOSTS)
- **Blocked-on-product (2 clusters, 7 stubs):** PreviewSourceDispatch (1 of 4 stubs has stale post-F16 docblock) · NotionShareTargetPreview deferred 3 (Issue #69)

Phase 3 picks drew from "ready" in ascending stub count.

### Phase 2 — gotcha consolidation (PR #76)

10 numbered gotchas with symptom / root cause / fix / surfaced-in-PR each, plus the three assertion-discipline rules (no OR-assertions in defense-in-depth, auth-gate absence-of-side-effects, custom registrations must not persist). The seam pattern from gotcha #10 is documented as reusable for any future controller reading `php://input` directly.

### Phase 3 — mechanical replays (PRs #77–#80)

In ascending stub count (per the audit's recommended order):

1. **PR #77 RouteHandler (1 stub)** — asserts the 6-rule rewrite table round-trips into `WP_Rewrite::wp_rewrite_rules()`, each rule rewriting to `index.php?outpost_route=<target>`, plus `QUERY_VAR` registration in `$wp->public_query_vars`.
2. **PR #78 MicropubPhotoWriteShape (2 stubs)** — fires `do_action('after_micropub', $input, $args)` directly with constructed input arrays. Tests structured `{value, alt}` shape and parallel-array shape; both write `_wp_attachment_image_alt` independent of AP plugin presence.
3. **PR #79 SyndicationCaptureFlow (6 stubs)** — full audit/capture/render loop, auth gate, per-user isolation, REST meta exposure, admin notice, the_excerpt filter. Uses `Pending_Capture_Detector::set_candidate_resolver_for_tests` seam to bypass the 30-second grace window.
4. **PR #80 ManualShareIntegration (9 stubs)** — intent endpoint (3 tests), chips endpoint (6 tests). Includes filter-hooked tests with try/finally cleanup per Rule 3.

Hits the 4-PR overnight cap. Did not start IosShortcutConnectionFlow (10) or AppearanceSettingsFlow (13) — those defer to a follow-up session.

## Hard-stop accounting

- **Hard stop #1 — Max 4 migration PRs (cluster #7 doesn't count):** ✅ honored. Cluster #7 (#75) + 4 Phase 3 (#77/#78/#79/#80) = 5 cluster PRs but only 4 count toward the cap.
- **Hard stop #2 — Two readiness failures in a row → halt entirely:** not triggered. Every Phase 3 candidate passed its readiness check on first try.
- **Hard stop #3 — 10th platform gotcha → halt:** triggered, then user overrode via the seam-PR pattern. Documented gotcha and pivoted; resumed migration once the production seam shipped.

## Memory entries written tonight

- `outpost_php_input_no_test_seam.md` — gotcha #10 description + resolution path
- `g99_pre_migration_readiness_check.md` (updated) — added the explicit hard-stop-after-readiness-check gate per cluster #5 PR-71-then-close incident

## Pending decisions (your morning queue)

- **Issue #69** — Notion disconnected preview UX (501 vs og:title fallback). Affects 3 NotionShareTargetPreview deferred stubs + the broader auth-required-source pattern.
- **Gotcha #4 expansion** — add canonical user-share URL hosts (`notion.so`, `www.notion.so`, `*.notion.site`, `music.apple.com`) to `REWRITABLE_HOSTS`? Unblocks 7+ stubs across Notion deferred + Apple Music if approved.
- **AP plugin in wp-env.json** — adding ActivityPub to wp-env's plugins list unblocks `CompanionActivityPubPassthroughTest` (4 stubs).
- **PreviewSourceDispatch stale stub** — test #2 (`explicit_source_id_unknown_returns_501`) asserts behavior that F16 changed. Update test or change SUT? (1 stub.)

## Cumulative G99 progress after tonight

| Status | Count |
|---|---|
| Migrated cleanly across clusters #1–#7 + Phase 3 #1–#4 | **54 stubs** |
| Blocked-on-platform pending REWRITABLE_HOSTS expansion or AP plugin add | 13 stubs |
| Blocked-on-product pending Issue #69 + PreviewSourceDispatch decision | 7 stubs |
| Ready but deferred to follow-up session (IosShortcutConnectionFlow + AppearanceSettingsFlow) | 23 stubs |
| **Total remaining stubs in repo** | ~43 |

Original inventory was ~97; tonight closed ~30 of them across cluster #7 + Phase 3.

## What I'd want eyes on first

1. **PR #74's production-callers grep proof** — confirms `set_payload_source_for_tests` has zero production callers, same posture as `OUTPOST_TESTING_PWA_SHELL`. The seam pattern unblocks any future controller reading `php://input`.
2. **PR #79 SyndicationCaptureFlow test 1 (`full_capture_loop_writes_audit_log_and_renders_u_syndication`)** — the most complex test of the night, multi-step end-to-end. Worth confirming the assertions match F12's actual behavior.
3. **Audit doc PR #73's "blocked-on-product" entries** — those 7 stubs need product decisions, not test-author work.

## What did NOT happen (intentionally)

- No merges. Every PR is awaiting your review.
- Nothing shipped to live (`courtneyr.dev`).
- No database mutations outside the wp-env tests-cli sandbox.
- No modifications to the `post-formats-for-block-themes` fork.
- No fix attempts on blocked-on-platform / blocked-on-product clusters — they're queued in PR #73's audit doc with explicit reasoning.

---

**Generated:** 2026-05-08 overnight queue, completed before morning. Files referenced: `docs/dev/g99-readiness-audit.md`, `docs/dev/integration-test-gotchas.md`. Memory entries in `~/.claude/projects/-Users-crobertson/memory/`.
