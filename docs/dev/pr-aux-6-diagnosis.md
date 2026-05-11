# PR-Aux-6-diagnosis — root-cause analysis for the 3 newly-unmasked failures from PR-A3b

Investigation-only document. No SUT changes in this PR. Produced 2026-05-11 against main commit `ca90394` (post-PR-A3b merge). Source: CI run state as of PR-A3b's local verification.

**This is the second diagnosis doc this week.** Its existence is part of the lesson — the original PR-Aux-5-diagnosis (PR #92) couldn't see what was downstream of short-circuits. Now eyes are on the next layer. The pattern is codified in `~/.claude/projects/-Users-crobertson/memory/feedback_anticipate_cascade.md`.

The 3 newly-unmasked failures cluster into **2 distinct root causes**:

| Cluster | Root cause | Failures | Fix scope |
|---|---|---:|---|
| **B-secondary-α** | Test doesn't establish WP loop context before `apply_filters('the_content'/'the_excerpt', ...)` — `get_the_ID()` returns 0 → renderer short-circuits | 2 (F1 + F3) | Tests-only, ~3 lines per test |
| **B-secondary-β** | Test doesn't initialize `WP_Screen` via `set_current_screen()` before `do_action('admin_notices')` — `get_current_screen()` returns null → notice short-circuits | 1 (F2) | Tests-only, ~1 line |

**Total failure count: 3 (unchanged from prompt input). No sub-failures uncovered. No 13th gotcha.**

---

## Diagnostic incompleteness — fourth instance recap

This week saw four instances of the same pattern. The pattern is now codified as a meta-rule in private memory.

| # | When | What was hidden | What unmasked it |
|---|---|---|---|
| 1 | PR-A2 schema audit | 2 invalid Platform_Config enum fields (`caption_via` AND `after_share`) | Fix 3 (Platform_Registry cache reset) |
| 2 | PR-Aux-5-diagnosis cluster A | Gotcha #12 (Source_Registry `$bootstrapped` drift) | PR-A1 + PR-A1.5 made dispatch paths run far enough |
| 3 | PR-A3c cluster C | Line-116 `public_query_vars` assertion failure | Line-74 permalink_structure fix |
| 4 | **PR-A3b cluster B-secondary (this diagnosis)** | **F1/F3 renderer + F2 admin notice short-circuits** | **Line-109 helper key-name fix** |

The shape: diagnosis works from observed symptoms; symptoms blocked behind upstream short-circuits get diagnosed as "1 issue" when reality is multiple. Mitigation: when fixing a short-circuit, anticipate cascade. Run after each fix; surface secondary defects as separate work; don't bundle scope expansion.

---

## Cluster B-secondary-α — WP loop context not set before `the_content`/`the_excerpt` filters

### Root cause

`Outpost_Syndication_Links_Renderer::register()` (at `includes/class-syndication-links-renderer.php:59-65`) hooks both filters at priority 99:

```php
add_filter( 'the_content', array( self::class, 'append_links' ), 99 );
add_filter( 'the_excerpt', array( self::class, 'append_links_excerpt' ), 99 );
```

Both callbacks (`append_links` at line 73-79, `append_links_excerpt` at line 87-93) call `get_the_ID()` to determine which post they're filtering content for. `get_the_ID()` is a WordPress-loop function that reads `$GLOBALS['post']->ID`. When `$GLOBALS['post']` is null (no loop context established), `get_the_ID()` returns `false` which casts to `0`. Both callbacks check `if ( $post_id <= 0 ) { return $content; }` — short-circuit, original content returned unchanged.

### Why the tests fail

Tests F1 (line 176) and F3 (line 331) call `apply_filters( 'the_content', ... )` and `apply_filters( 'the_excerpt', ... )` without setting up the WP post loop:

```php
// F1, line 176 — no setup_postdata or $GLOBALS['post'] set:
$content = apply_filters( 'the_content', get_post_field( 'post_content', $this->test_post_id ) );
$this->assertStringContainsString( 'u-syndication', $content );  // line 177 — FAILS
```

The renderer fires but `get_the_ID()` returns 0 → returns original content → no u-syndication anchor → assertion fails.

### Production code is correct

The renderer behaves exactly as a `the_content` filter callback should — it asks WordPress's loop API which post is currently being rendered. Production callers reach this filter via `WP_Query::the_post()` or `setup_postdata()`, both of which populate the global. The test bypasses both.

### Per-failure 5-point template entries

#### F1 — `full_capture_loop_writes_audit_log_and_renders_u_syndication`

1. **Current symptom:** `Failed asserting that '<p>Body content.</p>\n' contains "u-syndication".` at `SyndicationCaptureFlowTest.php:177`. Pre-line-177 assertions (lines 169-173) confirm the post meta `outpost_syndication_links` IS set with the captured URL.
2. **Production code exercised:** `Outpost_Syndication_Links_Renderer::append_links()` (line 73) → `get_the_ID()` → returns 0 → renderer returns input unchanged.
3. **Root cause hypothesis:** **Mechanical** (test setup missing — `$GLOBALS['post']` not populated).
4. **Proposed fix scope:** Tests-only, ~3 lines. Set `$GLOBALS['post'] = get_post( $this->test_post_id ); setup_postdata( $GLOBALS['post'] );` before line 176, and `wp_reset_postdata();` after the assertion (or in tearDown). Production code unchanged.
5. **Estimated complexity:** Small (~3 lines).

#### F3 — `the_excerpt_filter_also_includes_syndication_links`

1. **Current symptom:** `Failed asserting that '<p>A short excerpt.</p>\n' contains "u-syndication".` at `SyndicationCaptureFlowTest.php:336`.
2. **Production code exercised:** `Outpost_Syndication_Links_Renderer::append_links_excerpt()` (line 87) — same shape as F1, different filter.
3. **Root cause hypothesis:** **Mechanical**, same root cause as F1 (cascade).
4. **Proposed fix scope:** Same as F1 — set `$GLOBALS['post']` before line 331, reset after.
5. **Estimated complexity:** Small (~3 lines). Fix shape is identical to F1 — both tests need the same setup pattern.

---

## Cluster B-secondary-β — `WP_Screen` not initialized before `admin_notices` action

### Root cause

`Outpost_Pending_Syndication_Notice::register()` (at `includes/class-pending-syndication-notice.php:32-34`) hooks the action:

```php
add_action( 'admin_notices', array( self::class, 'maybe_render' ) );
```

`maybe_render()` at line 44-73 has 3 pre-render guards. The first one is the load-bearing one for this failure:

```php
public static function maybe_render(): void {
    if ( ! function_exists( 'get_current_screen' ) ) {
        return;
    }
    $screen = get_current_screen();
    if ( null === $screen || 'post' !== $screen->base ) {
        return;  // ← short-circuits here in the test
    }
    // ... rest of logic
}
```

`get_current_screen()` returns `null` until `WP_Screen::get()` has been called for the current request. In normal admin requests, WordPress core calls `set_current_screen()` during `admin-header.php` loading, which populates the global. The test fires `do_action('admin_notices')` without ever calling `set_current_screen()`, so `get_current_screen()` returns null → guard fires → notice's `render()` never runs → notices buffer stays empty.

### Why the tests' partial admin setup isn't sufficient

The test at lines 286-290 sets `$GLOBALS['post']`, `$_GET['post']`, `$_GET['action']`, `$GLOBALS['pagenow']`, `$GLOBALS['typenow']`. The test author KNEW about admin globals but didn't go all the way — `WP_Screen::get()` is a separate state from these globals. Setting `$GLOBALS['pagenow']` to `'post.php'` is necessary input for `WP_Screen::get()` but doesn't trigger it. The call has to be explicit.

### Production code is correct

`get_current_screen()` is the canonical guard for "is this running in an admin screen context?" The notice's defensive use of it prevents the notice from rendering in non-admin contexts (e.g., when something else fires `admin_notices` from a CLI script or REST API context). Removing the guard would be a regression.

### Per-failure 5-point template entry

#### F2 — `admin_post_edit_screen_shows_pending_notice`

1. **Current symptom:** `Failed asserting that a string is not empty.` at `SyndicationCaptureFlowTest.php:302`. The `ob_get_clean()` returns empty string.
2. **Production code exercised:** `Outpost_Pending_Syndication_Notice::maybe_render()` (line 44) — first guard `null === $screen` returns true → method returns early.
3. **Root cause hypothesis:** **Mechanical** (test setup missing — `set_current_screen()` not called).
4. **Proposed fix scope:** Tests-only, ~1 line. Call `set_current_screen( 'post.php' )` before `do_action( 'admin_notices' )` at line 293. Production code unchanged.
5. **Estimated complexity:** Small (~1 line).

---

## Why these are NOT a 13th gotcha

| Existing gotcha | Why this isn't an instance |
|---|---|
| #9 (`OUTPOST_TESTING_PWA_SHELL` missing) | About `exit()` short-circuiting; different mechanism |
| #11 (`active_plugins` filter missing) | A bootstrap-level env fix that benefits all tests; F1/F3/F2 are per-test setup concerns |
| #12 (Source_Registry `$bootstrapped` drift) | A production-API contract gap; F1/F3/F2 are stock WordPress test-authoring concerns |

F1/F3/F2 are **standard WordPress test-authoring hygiene** — when filtering `the_content`, set up the loop; when firing `admin_notices`, set up the current screen. These are concerns any WordPress integration test must handle; they're not Outpost-specific platform gotchas. The test-authoring discipline is captured in WP's own integration test documentation; we don't need a new gotcha number for it.

**Hard-stop check #2 result:** No 13th gotcha surfaced.

---

## Clustering analysis

F1 and F3 share root cause shape AND fix shape (set `$GLOBALS['post']` before the filter, reset after). They cluster cleanly into a single fix.

F2 is its own concern (current screen state, not loop context). Different fix shape.

**Hard-stop check #3 result:** F1+F3 confirmed to share root cause. Initial hypothesis from PR-A3b's surfacing held.

---

## Proposed PR-A3b-prime sequence

| PR | Scope | Files | Bypass # | Predicted CI delta |
|---|---|---|---:|---|
| **PR-A3b-prime-α** | Cluster B-secondary-α — set up post loop for F1 + F3 | `tests/integration/SyndicationCaptureFlowTest.php` (~6 lines across 2 tests) | 13 | 3F → 1F (F1 + F3 resolved) |
| **PR-A3b-prime-β** | Cluster B-secondary-β — set current screen for F2 | `tests/integration/SyndicationCaptureFlowTest.php` (~1 line) | 14 | 1F → 0F (F2 resolved) |

**Net after both:** 3F → 0F on cluster B-secondary. Recovery sequence closer to 0F+0E goal (still needs PR-A3a for the remaining 2 cluster A errors).

**Why two PRs not one:**
- Per the `feedback_anticipate_cascade.md` meta-rule: small PRs, each handling one wave. Each PR's CI delta is observable; each fix's assertion-delta is a structural smoking gun.
- F1+F3 share a fix; F2 is its own fix. The clusters are real; keep the PRs aligned with them.

**Alternative: single PR.** F1+F3+F2 all touch the same test file. A single PR handling all three would land cleanly and reduce overhead. The argument against: post the 4-occurrence diagnostic-incompleteness pattern, the meta-rule says "don't bundle." If a hidden defect lurks further down F1 or F2 (e.g., setup_postdata exposes a NEW assertion that fails), separating PRs gives cleaner attribution.

My recommendation: **two PRs** (α then β), following the meta-rule. The overhead is one extra bypass — bypass count rises to 14 instead of 13. Worth it for the discipline.

**Independence note:** PR-A3b-prime-α and PR-A3b-prime-β are independent; either can land first. Neither depends on the other's fix.

---

## Hard-stop check summary

- ✅ No SUT changes in this PR (diagnosis doc only)
- ✅ No 13th gotcha (F1/F3/F2 are stock WordPress test-authoring concerns, not platform issues)
- ✅ F1+F3 confirmed to share root cause (cluster B-secondary-α)
- ✅ F2 confirmed to be its own concern (cluster B-secondary-β)
- ✅ No sub-failures uncovered inside F1/F2/F3 — each is one root cause
- ✅ All proposed fix shapes within small-change thresholds (≤30 test lines; zero production lines)
- ✅ Diagnostic-incompleteness pattern explicitly named (fourth instance this week)

---

## Stub-count projection after PR-A3b-prime completes

| Metric | Current (main at `ca90394`) | After PR-A3b-prime-α | After PR-A3b-prime-β | After PR-A3a |
|---|---:|---:|---:|---:|
| Verified passing | 53 + (uncounted A3b-resolved) | +2 (F1+F3) | +1 (F2) | +2 (cluster A) |
| Failures | 3 | 1 | 0 | 0 |
| Errors | 2 | 2 | 2 | 0 |

After all three recovery PRs (A3b-prime-α, A3b-prime-β, A3a) land: **0F + 0E** on main. Recovery window exits.

---

## Cross-references

- **Memory:** `feedback_anticipate_cascade.md` (the meta-rule from 4 instances this week — applied recursively to this diagnosis pass)
- **Memory:** `outpost_test_assertion_discipline.md` (Rule 3 already revised for gotcha #12 in cluster A; no new rule needed for F1/F3/F2 since they're stock WP test concerns)
- **First diagnosis doc this week:** `docs/dev/pr-a3-diagnosis.md` (which couldn't see past the line-109 short-circuit)
- **PR-A3b commit:** `ca90394` — the line-109 helper fix that unmasked these 3 failures

---

**Generated:** 2026-05-11 evening (Phase 3 follow-up). Investigation-only. No production code or test code modified in this PR.
