# Outpost integration-test gotchas

Reference doc consolidating every test-infrastructure / test-authoring pitfall surfaced during the G99 stub-migration arc (PRs #64–#75). Read this when:

- Writing a new integration test that hits HTTP, REST, or the share-target controllers
- Investigating a CI green-locally / red-on-CI divergence
- Auditing a stub cluster against the readiness check

Each gotcha is numbered, dated, and linked to the PR where it surfaced. Anti-patterns to avoid are at the bottom under "Assertion discipline."

---

## Gotcha #1 — `wp_safe_remote_get` blocks 172.17.0.1 (Docker bridge)

**Symptom:** integration test sets up WireMock at `http://172.17.0.1:8080`, but `wp_safe_remote_get` returns WP_Error before the request lands at the mock server.

**Root cause:** `wp_safe_remote_get` enforces RFC 1918 private-IP blocking via the `http_request_host_is_external` filter chain. The Linux Docker bridge gateway (where WireMock binds inside wp-env) is `172.17.0.1`, an RFC 1918 address.

**Fix:** in `tests/integration/bootstrap.php`, register a `http_request_host_is_external` filter scoped to the mock-server host extracted from `OUTPOST_TEST_MOCK_SERVER_URL`. Production paths stay fully protected; only the configured mock host is whitelisted past the SSRF guard.

**Where surfaced:** PR #64 (G99 wp-env CI integration).

---

## Gotcha #2 — `wp_http_validate_url` port restrictions

**Symptom:** WireMock pinned to port 8888 / 8890 / any non-80/443/8080 port — `wp_safe_remote_get` rejects the URL with `outpost_og_invalid_url` before the request fires.

**Root cause:** `wp_http_validate_url()` only allows ports 80, 443, and 8080 by default. WP_HTTP_BLOCK_EXTERNAL has additional restrictions but the port allowlist is the load-bearing one.

**Fix:** pin WireMock to host port 8080 in `tests/mock-server/docker-compose.yml`. The container itself can bind to any port internally; the host-side mapping is what `wp_safe_remote_get` checks.

**Where surfaced:** PR #64 (port collision discovery during initial WireMock setup).

---

## Gotcha #3 — `wp_http_validate_url` TLD restrictions

**Symptom:** test fixture uses `https://example.test/...` URLs (RFC 6761 reserved TLD). `wp_safe_remote_get` rejects with `outpost_og_invalid_url` because `.test` doesn't resolve via DNS.

**Root cause:** `wp_http_validate_url()` runs DNS-resolvability checks before the `pre_http_request` filter (which is where `Outpost_Mock_Server_Filter` would rewrite the URL). The mock-server filter never gets a chance to fire.

**Fix:** integration tests bypass the rewriter entirely and hit `OUTPOST_TEST_MOCK_SERVER_URL` directly when calling primitives like `Outpost_Og_Inbound::fetch`. The rewriter is exercised separately by tests whose source-URL host passes `wp_http_validate_url` (Spotify's `open.spotify.com`, Notion's `api.notion.com`, etc.).

**Where surfaced:** PR #65 (cluster #1 G4b Og_Inbound migration).

---

## Gotcha #4 — `REWRITABLE_HOSTS` scope (API hosts only)

**Symptom:** auth-required source's fallback-to-extractor branch (disconnected user → og:title scrape on `www.notion.so`) escapes the mock-server and hits the real internet (or fails the SSRF guard entirely).

**Root cause:** `Outpost_Mock_Server_Filter::REWRITABLE_HOSTS` includes upstream API hosts (`api.notion.com`, `api.ouraring.com`, etc.) but NOT the user-shared canonical URL hosts (`notion.so`, `www.notion.so`, `*.notion.site`, `music.apple.com`, etc.). When `Outpost_Source_Notion::fetch_page` returns null (disconnected) and `handle_via_source` falls through to `wp_safe_remote_get($source_url)`, the request hits the canonical host which isn't rewritable.

**Fix paths (user decision required):**

1. **Expand `REWRITABLE_HOSTS`** to add the canonical-URL hosts. Production-code change. Defensible because `wp_safe_remote_get` to those hosts is rare in production (users hit them via browser).
2. **Per-test `http_request_host_is_external` escape hatch** with WireMock stubs at the canonical-URL path. Test-only.

**Affected clusters:** NotionShareTargetPreview (3 of 6 stubs blocked), G4bAppleMusicIntegrationTest (3 of 4 stubs blocked).

**Where surfaced:** PR #68 (cluster #3 NotionShareTargetPreview, closed).

**Memory:** `outpost_rewritable_hosts_scope.md`.

---

## Gotcha #5 — `wp-env logs` hangs CI cleanup

**Symptom:** CI `failure-cleanup` step that calls `wp-env logs` never returns; CI job times out at 30+ minutes instead of failing fast.

**Root cause:** `wp-env logs` follows by default (equivalent to `docker logs --follow`). In CI's non-interactive shell there's no signal to break out.

**Fix:** in `.github/workflows/ci.yml` cleanup steps, use `docker compose logs --tail=300` instead of `wp-env logs`. Captures the recent log lines without tailing.

**Where surfaced:** PR #64 (initial wp-env CI integration).

---

## Gotcha #6 — autoloader gap in `tests/_helpers/`

**Symptom:** integration tests skip silently — `class_exists('Outpost_Mock_Server')` returns false even though the helper file exists at `tests/_helpers/class-outpost-mock-server.php`.

**Root cause:** two-part gap:

1. `tests/_helpers/` not in `composer.json`'s `autoload-dev.classmap`
2. `tests/integration/bootstrap.php` doesn't load `vendor/autoload.php` before the WP test suite

**Fix:**

1. `composer.json` — add `"classmap": [ "tests/_helpers/" ]` to `autoload-dev`
2. `tests/integration/bootstrap.php` — `require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php'` before `tests_add_filter`

**Where surfaced:** PR #64 (cluster #1 setup).

---

## Gotcha #7 — Stacked-PR merge cascade

**Symptom:** downstream PR's branch silently auto-closes (with "Merged" status) when its base branch is deleted on the upstream PR's merge. Recovery requires reopening + retargeting + rebasing — multiple round-trips per cascade.

**Root cause:** GitHub's `gh pr merge --squash --delete-branch` deletes the head branch of the upstream PR. If a downstream PR's base was set to that branch, GitHub auto-closes the downstream PR (status: "Merged") because its base no longer exists.

**Fix:** BEFORE running `gh pr merge --delete-branch` on the upstream PR, run `gh pr edit <downstream-pr> --base main` for every downstream PR in the stack. The retarget makes downstream PRs independent of the upstream branch's lifecycle.

**Where surfaced:** PR #66 → #67 cascade. Recovery PR #67 cherry-picked the lost commit onto a fresh main-based branch.

**Memory:** `stacked_pr_merge_sequencing.md`.

---

## Gotcha #8 — Speculative-future-behavior in test docblocks

**Symptom:** stub docblock describes behavior the SUT no longer implements. Migration appears clean until you trace the dispatch chain and find the asserted code path doesn't exist anymore.

**Root cause:** stub docblocks were written speculatively before F-phase / G-phase work shipped. Subsequent SUT changes diverged from the originally-documented intent without updating the stubs. Cluster #3 (Notion) was the canonical case: docblocks said "falls through to legacy og:title path," but F5/G8b actually routes auth-required failures to `extractor_not_implemented` 501 because Notion's declared `extractor='api_json'` is a stub.

**Fix:** the readiness check (a/b/c/d) — particularly check (c) — is the prevention. Before opening a migration PR:

1. Read every stub's docblock
2. Trace each branch through the SUT
3. Confirm assertions match current SUT behavior
4. If any divergence, reclassify as blocked-on-product and file an issue for the user UX decision

**Where surfaced:** PR #68 (cluster #3) closed because 3 of 6 stubs had this issue.

**Memory:** `g99_pre_migration_readiness_check.md`.

---

## Gotcha #9 — `OUTPOST_TESTING_PWA_SHELL` needed in integration bootstrap

**Symptom:** integration test calls a controller that ends with `Outpost_PWA_Shell::halt()` → halt does `exit()` → PHPUnit dies mid-suite.

**Root cause:** `Outpost_PWA_Shell::halt()` checks `defined( 'OUTPOST_TESTING_PWA_SHELL' )` to decide whether to exit. The constant was set in `tests/bootstrap.php` (unit) but not `tests/integration/bootstrap.php`. No integration test before cluster #4 exercised a halt path, so the gap wasn't caught.

**Fix:** in `tests/integration/bootstrap.php`, define the constant the same way the unit bootstrap does:

```php
if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
    define( 'OUTPOST_TESTING_PWA_SHELL', true );
}
```

Production code only does `defined()` reads (8 sites under `includes/`); proof: `grep -rn "define( 'OUTPOST_TESTING_PWA_SHELL'" includes/ outpost.php uninstall.php pwa/` returns zero matches.

**Where surfaced:** PR #70 (cluster #4 SpotifyEndToEnd).

---

## Gotcha #10 — `php://input` has no test seam

**Symptom:** controller reads JSON body via `file_get_contents('php://input')`. Under PHPUnit-CLI, `php://input` is empty by default. Tests cannot inject a body.

**Root cause:** PHP's `file_get_contents('php://input')` reads from the HTTP request body in CGI mode and from STDIN in CLI mode. PHPUnit doesn't redirect STDIN per-test. `stream_wrapper_unregister('php')` followed by `stream_wrapper_register('php', MockClass::class)` redirects ALL `php://*` paths (input + memory + temp + stdin + stdout + stderr + filter), risking breakage of WP/PHP internals that touch any of those.

**Fix (production-side test seam):** add a static property + setter on the controller. `read_*_payload()` consults the seam first, falls back to `file_get_contents('php://input')` when null:

```php
/**
 * Test-only override for the JSON body source. Production never sets
 * this; integration tests use it to inject bodies without
 * stream_wrapper hacks. See docs/dev/integration-test-gotchas.md
 * § gotcha #10.
 *
 * @var callable|null
 */
private static $payload_source_for_tests = null;

public static function set_payload_source_for_tests( ?callable $reader ): void {
    self::$payload_source_for_tests = $reader;
}

private static function read_json_payload(): ?array {
    if ( null !== self::$payload_source_for_tests ) {
        $raw = (string) call_user_func( self::$payload_source_for_tests );
    } else {
        $raw = (string) file_get_contents( 'php://input' );
    }
    if ( '' === $raw ) {
        return null;
    }
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : null;
}
```

**Test-side hygiene:** tests must clear the seam in `tearDown()`:

```php
protected function tearDown(): void {
    Outpost_Shortcut_Controller::set_payload_source_for_tests( null );
    // ... other teardown
}
```

**Production-callers proof (the test-never-leaks property):**

```bash
$ grep -rn "set_payload_source_for_tests" includes/ outpost.php uninstall.php pwa/
includes/sources/class-shortcut-controller.php:120: ...   # only the definition
```

Zero production callers. Same posture as `OUTPOST_TESTING_PWA_SHELL`.

**Reusable pattern:** any future controller that reads `php://input` directly (or any other unmockable body source) should ship with `set_payload_source_for_tests` from the start. Apply this seam pattern preemptively rather than retroactively after a cluster blocks.

**Where surfaced:** ShortcutDispatchTest cluster #7 attempt → seam shipped as PR #74 → cluster #7 migration as PR #75 (stacked on #74).

**Memory:** `outpost_php_input_no_test_seam.md`.

---

# Assertion discipline (anti-patterns to avoid)

Three rules surfaced during the PR #70/#71 patterns review. Not gotchas in the platform sense — these are test-authoring anti-patterns to avoid.

## Rule 1 — No OR-assertions in defense-in-depth contexts

**Wrong:**

```php
$this->assertTrue(
    str_contains( $url, 'picker=' ) || str_contains( $url, 'source=unknown' ),
    'Should route to picker or unknown'
);
```

The OR weakens both assertions — the test passes if EITHER branch holds, masking a regression in the other.

**Right:** for defense-in-depth tests where the architecture is "Layer A protects AND Layer B protects," require BOTH assertions on their own lines, each with its own failure message:

```php
$this->assertNotNull( $redirect_url, 'Layer A: dispatch must build redirect (pure URL→decision)' );
$this->assertTrue( is_wp_error( $fetch_response ), 'Layer B: SSRF guard must independently block fetch' );
```

**Surfaced:** PR #70/#71 review. Cluster #6 ShareTargetDispatchTest applied the rule first (test 5 layered SSRF).

## Rule 2 — Auth-gate tests need absence-of-side-effects assertions

A test asserting "401 fires for unauthenticated request" is trivially passing — any erroring path returns *something*. The meaningful assertion is: the protected work didn't fire. Specifically check absence of:

- Redirect captured (use the `wp_redirect` filter capture pattern)
- Transients written (DB `COUNT(*)` on the expected key prefix)
- Hooks fired (`did_action()` checks)
- Log entries (no `error_log` output for this user)
- DB rows mutated (row count unchanged for the affected table)

The rule catches TOCTOU regressions where the auth check is moved AFTER dispatch.

**Surfaced:** PR #70/#71 review. Applied in cluster #6 (test 2: `post_unauthenticated_returns_401`) and cluster #7 (tests 2/3/4).

## Rule 3 — Custom registrations must not persist across tests

When a test registers a fake source / companion / filter callback into a static-state singleton (e.g., `Outpost_Source_Registry::register`, `add_action('outpost_sources_init', ...)`), the registration MUST be undone in tearDown OR scoped to a single test method via try/finally.

**Pattern:**

```php
public function unambiguous_dispatch_writes_prefill_transient(): void {
    $fake_init = static function (): void { /* register fake */ };
    add_action( 'outpost_sources_init', $fake_init, 5 );
    Outpost_Source_Registry::reset_for_tests();
    do_action( 'outpost_sources_init' );

    try {
        // ... test body
    } finally {
        remove_action( 'outpost_sources_init', $fake_init, 5 );
        Outpost_Source_Registry::reset_for_tests();
        do_action( 'outpost_sources_init' );
    }
}
```

The reset+replay restores the production source set; without cleanup, the fake source leaks into the next test, where it can match URLs the next test didn't expect to claim. Symptom: tests pass in isolation, fail when the class runs in suite order.

**Surfaced:** PR #70/#71 review. Applied in cluster #6 test 4 (`unambiguous_dispatch_writes_prefill_transient`).

---

# How to apply this doc

**When writing a new integration test:**

1. Run the readiness check (a/b/c/d) per `g99_pre_migration_readiness_check.md`
2. Scan this doc for any pattern your SUT triggers (HTTP fetches → #1–#4, php://input → #10, halts → #9, etc.)
3. Apply the relevant fixes preemptively
4. If you discover a gotcha not listed here, file an 11th entry below with the same shape

**When reviewing an integration-test PR:**

1. Check assertion discipline rules 1–3
2. Verify any HTTP-touching test correctly handles #1–#4
3. Verify any controller-level test correctly handles #9–#10
4. If the PR adds a new gotcha number, confirm the consolidation doc was updated

**When investigating a CI green-locally / red-on-CI divergence:**

The likely candidates: #1 (Docker bridge), #2 (port restrictions), #6 (autoloader), or a missing wp-env plugin. Run the audit's blocked-on-platform list against the failing test's dependencies.

---

**Last updated:** 2026-05-08 (overnight queue Phase 2). Next update: when an 11th gotcha surfaces or one is resolved by a production change.
