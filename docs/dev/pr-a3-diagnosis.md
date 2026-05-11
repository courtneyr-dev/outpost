# PR-Aux-5-diagnosis — root-cause analysis for the 8 remaining red on main

Investigation-only document. No SUT changes in this PR. Produced 2026-05-11 against main commit `176c883` (post-PR-A2 merge). Source: CI run [25642733145](https://github.com/courtneyr-dev/outpost/actions/runs/25642733145) Integration job.

The 8 problems flagged for PR-A3 scope by the G99 honest-stub-count audit cluster into **3 distinct root causes**, not 8 independent bugs. PR-A3 sequencing in §"Proposed fix sequence" accounts for the clustering.

---

## Cluster summary

| Cluster | Root cause | Test count | Tests | Fix scope |
|---|---|---:|---|---|
| **A** | `Outpost_Source_Registry::$bootstrapped` state drift (NEW gotcha #12) | 2 | `ShareTargetDispatchTest::unambiguous_dispatch_writes_prefill_transient`, `ShareTargetDispatchTest::localhost_url_dispatches_but_b2_blocks_fetch` | Production +5 lines (one new test seam) + tests +6 lines (2 call sites) |
| **B** | `seed_pending_entry()` helper uses wrong key name `audit_log_id` (SUT returns `id`) | 5 | `SyndicationCaptureFlowTest::full_capture_loop_writes_audit_log_and_renders_u_syndication`, `::pending_endpoint_only_returns_users_own_posts`, `::rest_api_exposes_outpost_syndication_links_post_meta`, `::admin_post_edit_screen_shows_pending_notice`, `::the_excerpt_filter_also_includes_syndication_links` | Tests-only, 2 lines |
| **C** | Permalink structure not set in test env → `wp_rewrite_rules()` returns `''` | 1 | `RouteHandlerIntegrationTest::rewrite_flow_dispatches_each_route_to_the_pwa_shell` | Bootstrap-or-test, 1-2 lines |

**Total problem count: 8 (unchanged from prompt input). No sub-problems uncovered. No 13th gotcha.**

---

## Cluster A — Source_Registry bootstrap state drift

### Gotcha #12 — full entry

**Symptom shape:** A test that follows `outpost_test_assertion_discipline.md` Rule 3 verbatim (custom-source registration via `add_action` + `reset_for_tests` + manual `do_action`) hits `InvalidArgumentException: Outpost_Source_Registry: source id "..." already registered` on the next implicit dispatch.

**Root cause — contract gap:**

`Outpost_Source_Registry` has two state vars: `$sources` (array of registered sources) and `$bootstrapped` (whether the boot action has fired). The lazy bootstrap path (`ensure_bootstrapped()`) sets `$bootstrapped=true` and fires `do_action('outpost_sources_init')` in lockstep. But two other state operations break the lockstep:

| Operation | Touches `$sources`? | Touches `$bootstrapped`? |
|---|---|---|
| `register($source)` | Appends (line 113) | No |
| `reset_for_tests()` | Clears (line 242) | Clears to `false` (line 243) |
| `ensure_bootstrapped()` | Populated by action callbacks (line 192) | Sets to `true` BEFORE action (line 181) |
| Manual `do_action('outpost_sources_init')` from test code | Populated by action callbacks | **NOT TOUCHED** ← the gap |

Rule 3's documented pattern uses manual `do_action()` in both the setup and the finally block. Neither call sets `$bootstrapped=true`. The next implicit `ensure_bootstrapped()` from a dispatch path therefore re-fires the action against an already-populated `$sources`, producing `register()` duplicate-id throws.

**Why not classified as instance of existing gotchas:**

| Existing gotcha | Why not |
|---|---|
| #9 (`OUTPOST_TESTING_PWA_SHELL` missing in bootstrap) | About `exit()` short-circuiting; different mechanism |
| #10 (`php://input` no test seam) | About body injection; unrelated |
| #11 (`active_plugins` filter missing) | About `is_plugin_active()`; different static, different contract |

Gotcha #12 is a **production-API contract gap** — the static-state coordination between `reset_for_tests()` and the post-action `$bootstrapped` flag is missing a complementary test seam.

### Proposed fix — Approach A (test seam, ~5 production lines)

Mirrors 4 existing precedents in the codebase:

1. `Outpost_Manual_Share_Pending_Capture_Detector::set_candidate_resolver_for_tests()` — F12
2. `Outpost_Shortcut_Controller::set_payload_source_for_tests()` — gotcha #10
3. `Outpost_Share_Target_Controller::set_redirect_callback_for_tests()` — cluster #4 (PR-A1)
4. `Outpost_Manual_Share_Platform_Registry::reset_for_tests()` — PR-A2

**Production change** (`includes/sources/class-source-registry.php`, ~5 lines):

```php
/**
 * Test seam — mark the registry as bootstrapped without firing the
 * action. Use this AFTER a manual `do_action( 'outpost_sources_init' )`
 * so a subsequent implicit `ensure_bootstrapped()` doesn't re-fire the
 * action against an already-populated $sources (gotcha #12). Zero
 * production callers; the seam is for assertion-discipline Rule 3
 * patterns only.
 */
public static function mark_bootstrapped_for_tests(): void {
    self::$bootstrapped = true;
}
```

Production-callers grep test: `grep -rn "mark_bootstrapped_for_tests" includes/ outpost.php uninstall.php pwa/` must return zero matches (same posture as the 4 existing seams).

**Test change** (`tests/integration/ShareTargetDispatchTest.php`, ~6 lines across 2 call sites):

Setup at line 268-269:
```php
Outpost_Source_Registry::reset_for_tests();
do_action( 'outpost_sources_init' );
Outpost_Source_Registry::mark_bootstrapped_for_tests();  // NEW
```

Finally at line 289-290:
```php
Outpost_Source_Registry::reset_for_tests();
do_action( 'outpost_sources_init' );
Outpost_Source_Registry::mark_bootstrapped_for_tests();  // NEW
```

### Rule 3 revision required (handled separately by user)

`outpost_test_assertion_discipline.md` Rule 3's documented pattern is incomplete. The revised pattern is:

```php
add_action( 'outpost_sources_init', $fake_init, 5 );
Outpost_Source_Registry::reset_for_tests();
do_action( 'outpost_sources_init' );
Outpost_Source_Registry::mark_bootstrapped_for_tests();  // ADD

try {
    // ... test body
} finally {
    remove_action( 'outpost_sources_init', $fake_init, 5 );
    Outpost_Source_Registry::reset_for_tests();
    do_action( 'outpost_sources_init' );
    Outpost_Source_Registry::mark_bootstrapped_for_tests();  // ADD
}
```

The user will update the principles doc to revise Rule 3. The change to the in-repo `docs/dev/integration-test-gotchas.md` (adding gotcha #12 and updating Rule 3) lands in PR-A3a.

### Per-problem 5-point template entries

#### Problem A.1 — `ShareTargetDispatchTest::unambiguous_dispatch_writes_prefill_transient`

1. **Current symptom:** `InvalidArgumentException: Outpost_Source_Registry: source id "sharetarget-test-fake" already registered.` at `class-source-registry.php:97`, fired from `ShareTargetDispatchTest.php:264` (the `register($fake)` call inside the `$fake_init` closure) via the implicit `ensure_bootstrapped()` triggered by `dispatch_share_target_post` at line 272.
2. **Production code exercised:** `Outpost_Source_Registry::register()` (line 86), called by the test's `$fake_init` closure (line 254-265). The test is asserting the registry surfaces a custom unambiguous source in dispatch.
3. **Root cause hypothesis:** **Mechanical** (test pattern incomplete; gotcha #12).
4. **Proposed fix scope:** Production+tests. Production: new `mark_bootstrapped_for_tests()` seam (~5 lines). Tests: 2 call sites updated (~6 lines).
5. **Estimated complexity:** Small (~11 lines total, both files).

#### Problem A.2 — `ShareTargetDispatchTest::localhost_url_dispatches_but_b2_blocks_fetch`

1. **Current symptom:** `InvalidArgumentException: Outpost_Source_Registry: source id "spotify" already registered.` at `class-source-registry.php:97`, fired from `outpost.php:350` (production source registration) via the implicit `ensure_bootstrapped()` triggered by `dispatch_share_target_post` at line 307.
2. **Production code exercised:** `Outpost_Source_Registry::ensure_bootstrapped()` (line 177) firing `outpost_sources_init`, which `outpost.php:350` subscribes to for `Source_Spotify` registration.
3. **Root cause hypothesis:** **Cascade** (problem A.1 leaves `$bootstrapped=false` with `$sources` populated; this test triggers re-firing). Same root cause as A.1.
4. **Proposed fix scope:** Same fix as A.1 — once A.1's `finally` block uses `mark_bootstrapped_for_tests()`, this cascade can't happen.
5. **Estimated complexity:** Zero additional — fixed by A.1's fix.

---

## Cluster B — `seed_pending_entry()` helper key-name mismatch

All 5 SyndicationCaptureFlowTest errors share `Undefined array key "audit_log_id"` at `SyndicationCaptureFlowTest.php:109`. Single root cause, single fix.

### Root cause

`Outpost_Manual_Share_Audit_Log::add_entry()` (line 100 of `class-audit-log.php`) returns an entry shape with the canonical id key `'id'` (line 102):

```php
$entry = array(
    'id'           => self::generate_id(),
    'version'      => self::ENTRY_VERSION,
    'platform_id'  => $platform_id,
    // ...
);
```

The class-level docblock at line 13 confirms this is canonical: `'id' => 'a1b2c3d4-...'  // server-generated, returned to PWA`. The internal `update_entry()` method at line 138 also reads by `'id'`.

The test helper at line 109 expects a different key:

```php
$audit_log_id = (string) $entry['audit_log_id'];   // line 109 — wrong key
```

The error is the PHP 8 `Undefined array key` notice promoted to a thrown error under WP's strict test mode.

The helper also misuses the key a second time at line 114 when matching the seeded entry by id:

```php
if ( ( $e['audit_log_id'] ?? '' ) === $audit_log_id ) {   // line 114 — same bug
```

Because `get_entries()` returns entries with `id` (not `audit_log_id`), this comparison NEVER matches and the `added_at` rollback NEVER runs. Even if the line 109 access didn't throw, the seeded post's `added_at` would stay at the current timestamp and the detector's 30-second grace check would suppress it.

### Why the test code believed the key was `audit_log_id`

The capture endpoint's REQUEST body uses `audit_log_id` as the external API parameter name (test code lines 154, 191, 255, 322). The test author conflated the API parameter name with the internal storage key. The API parameter and the storage key are deliberately different (the API exposes a more descriptive name; storage keeps it short). The test helper crossed the boundary.

### Per-problem 5-point template entries

All 5 share the same root cause; entries below note any per-test specifics.

#### Problem B.1 — `full_capture_loop_writes_audit_log_and_renders_u_syndication`

1. **Current symptom:** `Undefined array key "audit_log_id"` at `SyndicationCaptureFlowTest.php:109` (the seed helper). Test method at line 137 calls `seed_pending_entry()` at line 138 → helper throws on line 109.
2. **Production code exercised:** `Outpost_Manual_Share_Audit_Log::add_entry()` (returns entry with `id` not `audit_log_id`).
3. **Root cause hypothesis:** **Mechanical** (test helper uses wrong key name).
4. **Proposed fix scope:** Tests-only. 2-line change in helper at lines 109 and 114: `audit_log_id` → `id`.
5. **Estimated complexity:** Small (~2 lines).

#### Problem B.2 — `pending_endpoint_only_returns_users_own_posts`

1. **Current symptom:** Same as B.1 — fails at line 109 when called from line 212.
2. **Production code exercised:** Same (`Audit_Log::add_entry`).
3. **Root cause hypothesis:** **Cascade of B.1** (same helper).
4. **Proposed fix scope:** Zero additional — fixed by B.1's fix.
5. **Estimated complexity:** Zero.

#### Problem B.3 — `rest_api_exposes_outpost_syndication_links_post_meta`

1. **Current symptom:** Same — fails at line 109 when called from line 246.
2. **Production code exercised:** Same.
3. **Root cause hypothesis:** **Cascade of B.1**.
4. **Proposed fix scope:** Zero additional.
5. **Estimated complexity:** Zero.

#### Problem B.4 — `admin_post_edit_screen_shows_pending_notice`

1. **Current symptom:** Same — fails at line 109 when called from line 281.
2. **Production code exercised:** Same.
3. **Root cause hypothesis:** **Cascade of B.1**.
4. **Proposed fix scope:** Zero additional.
5. **Estimated complexity:** Zero.

#### Problem B.5 — `the_excerpt_filter_also_includes_syndication_links`

1. **Current symptom:** Same — fails at line 109 when called from line 313.
2. **Production code exercised:** Same.
3. **Root cause hypothesis:** **Cascade of B.1**.
4. **Proposed fix scope:** Zero additional.
5. **Estimated complexity:** Zero.

**Cluster B net:** 5 problems → 1 two-line fix.

### Secondary concern — `added_at` rollback never ran

Even after the key-name fix, problem B.1 also needs verification that the `added_at` rollback actually rolls the timestamp. Pre-fix, the foreach at line 113-117 never entered its body (key mismatch). Post-fix it will run, and the detector's 30-second grace check should allow the entry through. Worth one explicit assertion in test 1 that the detector returns the post (currently asserts `$pending_data` is non-empty at line 145, which is the right assertion shape).

---

## Cluster C — RouteHandler permalink structure missing

### Root cause

The test expects pretty permalinks (rewrite rules table) but the test environment runs WordPress on default plain permalinks (`?p=ID`).

`RouteHandlerIntegrationTest::setUp()` at line 47-48 calls:
```php
Outpost_Route_Handler::register_rewrite_rules();
flush_rewrite_rules( false );
```

Both succeed. But `WP_Rewrite::wp_rewrite_rules()` builds rules from `WP_Rewrite::rewrite_rules()`, which short-circuits when `permalink_structure` is empty (WordPress core behavior). The result: `wp_rewrite_rules()` returns `''` (empty string), not an empty array.

Test at line 73-74:
```php
$rewrite_rules = $wp_rewrite->wp_rewrite_rules();
$this->assertIsArray( $rewrite_rules );   // ← fails: '' is not array
```

`.wp-env.json` doesn't configure `permalink_structure`; `tests/integration/bootstrap.php` doesn't either. So the test env runs plain permalinks by default, and any test that depends on rewrite rules will fail this way.

### Production code is correct

`Outpost_Route_Handler::rules()` returns the right 6-rule table. `register_rewrite_rules()` calls `add_rewrite_rule()` for each. The bug is the environment, not the SUT.

### Per-problem 5-point template entry

#### Problem C.1 — `rewrite_flow_dispatches_each_route_to_the_pwa_shell`

1. **Current symptom:** `Failed asserting that '' is of type "array".` at `RouteHandlerIntegrationTest.php:74`. `wp_rewrite_rules()` returns `''` because the test environment has empty `permalink_structure`.
2. **Production code exercised:** `Outpost_Route_Handler::register_rewrite_rules()` (correct), `WP_Rewrite::wp_rewrite_rules()` (returns empty string when no permalink structure — WordPress core behavior, not a bug).
3. **Root cause hypothesis:** **Environmental** (test bootstrap doesn't set `permalink_structure` before WP_Rewrite builds rules).
4. **Proposed fix scope:** Two candidates, both 1-2 lines. **Recommended:** integration bootstrap. **Alternative:** per-test setUp.
   - **Bootstrap** (`tests/integration/bootstrap.php`): add `update_option('permalink_structure', '/%postname%/')` to the `muplugins_loaded` callback. Every future test that depends on rewrite rules benefits. 1 line.
   - **Per-test** (`tests/integration/RouteHandlerIntegrationTest.php` setUp before line 47): add `update_option('permalink_structure', '/%postname%/')` then `$wp_rewrite->init()` to refresh. 2 lines. Narrower blast radius; explicit dependency declaration in the test.
5. **Estimated complexity:** Small (1-2 lines depending on choice).

### Bootstrap vs per-test recommendation

I recommend the **bootstrap fix**. Reasons:
- Pretty permalinks are closer to the production-config users actually run (WordPress.org / managed-WP installs default to `/%postname%/` after first admin login).
- Future tests that need rewrite rules (the deferred RouteHandler scenarios in `docs/dev/g99-readiness-audit.md` — Phase H settings, admin URL surfaces) inherit it without rediscovering this.
- Same posture as the integration bootstrap's other env-fixing filters (gotcha #1's `http_request_host_is_external`, gotcha #11's `option_active_plugins`).

Per-test would be the right call if pretty permalinks could break OTHER tests (e.g., a test asserting that plain permalinks work). No such test exists in the current suite.

---

## Proposed PR-A3 sequence

| PR | Scope | Files | Bypass # | Predicted CI delta |
|---|---|---|---:|---|
| **PR-A3a** | Cluster A fix — production new method + tests + docs | `includes/sources/class-source-registry.php` (+5), `tests/integration/ShareTargetDispatchTest.php` (+6), `docs/dev/integration-test-gotchas.md` (+gotcha #12 entry, ~60 lines) | 9 | 7E → 5E (cluster A's 2 errors resolved) |
| **PR-A3b** | Cluster B fix — test helper key-name correction | `tests/integration/SyndicationCaptureFlowTest.php` (~2 lines) | 10 | 5E → 0E (cluster B's 5 errors resolved); 1F unchanged |
| **PR-A3c** | Cluster C fix — integration bootstrap permalink config | `tests/integration/bootstrap.php` (~1 line) | 11 | 1F → 0F (cluster C's 1 failure resolved) |

**Predicted end-state after PR-A3c merges:** **0F + 0E** on main. Recovery window exit condition reached.

**Why three PRs not one:**
- Cluster A's production change should be reviewable independently (small, but it adds a new public API method).
- Cluster B is the smallest fix in the recovery sequence; landing it solo gives high signal-to-noise.
- Cluster C is environmental scope; lands separately to keep production-code-only PRs distinct from env-config PRs.

**Why not stacked PRs:** the three fixes are independent. Each can land on its own against main without sequencing. The merge order doesn't matter — none of them depends on another's fix.

**Bypass count trajectory:** 7 (current) → 8 (PR-Aux-5-diagnosis when this lands) → 9 → 10 → 11 → main green → bypass-during-recovery window closes.

---

## Hard-stop check summary

- ✅ No new SUT changes in this PR (diagnosis doc only)
- ✅ No 13th gotcha (only #12 surfaced; documented above)
- ✅ Cluster counts match prompt input: A=2, B=5, C=1, total=8
- ✅ No cluster's problem count understated by yesterday's notes
- ✅ All 3 fix shapes are within the small-change thresholds (≤15 production lines, ≤30 test lines per PR)
- ✅ Approach A for cluster A confirmed against 4 existing test-seam precedents
- ✅ Approach for cluster B is purely tests-only (2 lines)
- ✅ Approach for cluster C is bootstrap-only (1 line) — production code is correct

---

## Stub-count projection after PR-A3 completes

| Metric | Current (main at 176c883) | After PR-A3a | After PR-A3b | After PR-A3c |
|---|---:|---:|---:|---:|
| Verified passing | 55 | 57 | 62 | **63** |
| Failures | 1 | 1 | 1 | 0 |
| Errors | 7 | 5 | 0 | 0 |
| Skipped | 39 | 39 | 39 | 39 |

PR-A3 closes the recovery arc. Subsequent work (PR-A4 honest audit refresh, then resume new cluster migration) starts from 63 of 102 verified passing on a green main.

---

## Cross-references

- **Memory:** `outpost_test_assertion_discipline.md` (Rule 3 — incomplete, surfaces in cluster A)
- **Doc to update in PR-A3a:** `docs/dev/integration-test-gotchas.md` (add gotcha #12; revise Rule 3 cross-reference)
- **Doc to update in PR-A4:** `docs/dev/g99-honest-stub-count.md` (refresh per-cluster table post-recovery)
- **Authoritative for this analysis:** CI Integration job log at run [25642733145](https://github.com/courtneyr-dev/outpost/actions/runs/25642733145)

---

**Generated:** 2026-05-11 morning, Phase 3 of recovery sequence. Investigation-only. No production code or test code modified in this PR.
