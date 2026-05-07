# G99 stub-migration inventory

PR #56 shipped the WireMock scaffolding. The "actual point" was migrating ~14 currently-skipped integration test stubs from G3.5a and G4b to use the WireMock-based mock server. This doc inventories ALL stubs (97 total across 16 files) so the migration can ship as a sequence of focused per-cluster PRs.

## Why this isn't one giant PR

Two reasons:

1. **CI infrastructure gap.** The current `.github/workflows/ci.yml` runs unit tests + lint only. There's no integration-tests job that spins up wp-env or starts a WireMock container. Migrating the `markTestSkipped` calls to real WireMock-backed assertions would produce tests that pass locally only when Docker's running, and never in CI. The first follow-up PR needs to add the integration job.
2. **Per-cluster blast radius.** 97 stubs across 16 files spans G3.5a + G4b + G8b + F1-F17 + FX + FY. Migrating all of them in one PR exceeds any reasonable diff cap and makes bisecting failures impossible. Per-cluster commits (locked decision #1 in C1's prompt) are the right granularity, and per-cluster PRs are even better.

## Inventory by file (97 total stubs)

Counted via `grep -c "markTestSkipped" tests/integration/*.php`.

### G-phase (in-scope per C1's "G3.5a + G4b" reading)

| File | Stub count | Cluster | Notes |
|---|---|---|---|
| `tests/integration/G4bOgInboundIntegrationTest.php` | 3 | G4b Og_Inbound | External fetch + 404 + dispatch tests. Smallest cluster — best demonstration target. |
| `tests/integration/G4bCompositeInboundIntegrationTest.php` | 4 | G4b Composite | Composite primitive end-to-end. |
| `tests/integration/G4bAppleMusicIntegrationTest.php` | 4 | G4b Apple Music demo | Composite-primitive showcase via Apple Music + iTunes Lookup. |
| `tests/integration/NotionShareTargetPreviewTest.php` | 6 | G3.5a / G8b | Authenticated Notion fetch through share-target preview. Depends on G8b (PR #48) merged — done. |

**G-phase subtotal: 17 stubs.** This is what the C1 prompt's "~14" estimate was pointing at.

### F-phase (out of C1's stated scope, but in the same migration system)

| File | Stub count | Cluster |
|---|---|---|
| `tests/integration/PreviewSourceDispatchTest.php` | 4 | F5 preview-endpoint dispatch |
| `tests/integration/MicropubPhotoWriteShapeTest.php` | 2 | F3 photo upload shape |
| `tests/integration/SpotifyEndToEndTest.php` | 9 | F7 Spotify source |
| `tests/integration/YouTubeEndToEndTest.php` | 12 | F15 YouTube source |
| `tests/integration/ShareTargetDispatchTest.php` | 5 | F6 share-target dispatcher |
| `tests/integration/ShortcutDispatchTest.php` | 5 | F6 iOS Shortcut bridge |
| `tests/integration/ManualShareIntegrationTest.php` | 9 | F9–F13 manual share |
| `tests/integration/SyndicationCaptureFlowTest.php` | 6 | F12 silo-URL writeback |
| `tests/integration/CompanionActivityPubPassthroughTest.php` | 4 | F1 ActivityPub passthrough |
| `tests/integration/RouteHandlerIntegrationTest.php` | 1 | A2 rewrite rules |

**F-phase subtotal: 57 stubs.**

### Cross-cutting (FX, FY)

| File | Stub count | Cluster |
|---|---|---|
| `tests/integration/IosShortcutConnectionFlowTest.php` | 10 | FX iOS Shortcut bridge |
| `tests/integration/AppearanceSettingsFlowTest.php` | 13 | FY appearance settings + theme |

**Cross-cutting subtotal: 23 stubs.**

## Recommended migration order

Per the principle "smallest/safest first":

1. **Add integration job to CI** (~600 lines). New PR. No stub changes — pure infrastructure. Spin up wp-env + WireMock as sidecar in `.github/workflows/ci.yml`. After this PR merges, every subsequent migration can verify in CI.
2. **G4b Og_Inbound** (3 stubs, ~200 lines). Smallest cluster, simplest dispatch.
3. **G4b Composite + Apple Music** (8 stubs, ~400 lines). The Composite primitive's first real test. Stack on G4b Og_Inbound or independent (no shared fixtures).
4. **G3.5a / G8b Notion share-target** (6 stubs, ~300 lines). Already-merged Notion source class is the consumer.
5. **F-phase per-cluster** (~57 stubs across 10 PRs). Spotify, YouTube, ManualShare, SyndicationCapture, ShareTarget, Shortcut, ActivityPub, MicropubPhoto, PreviewSourceDispatch, RouteHandler. Each its own PR.
6. **FX / FY** (~23 stubs across 2 PRs). iOS Shortcut connect + appearance settings. Largest clusters last.

## Migration pattern (per stub)

Once CI is green for integration tests, every migration follows this shape:

```php
// BEFORE (current):
public function og_inbound_fetches_from_external_url(): void {
    $this->markTestSkipped( 'wp-env mock-server routing pending.' );
}

// AFTER:
public function og_inbound_fetches_from_external_url(): void {
    Outpost_Mock_Server::reset();
    Outpost_Mock_Server::stub_from_fixture( 'og-inbound/article-fixture-success.json' );

    $result = Outpost_Og_Inbound::fetch( 'https://example.test/og-inbound/article' );

    $this->assertIsArray( $result );
    $this->assertSame( 'Sample Article Title', $result['title'] );
    // ... assert the 9-key response shape per the docblock steps ...
}
```

The fixture lives at `tests/fixtures/mock-server/og-inbound/article-fixture-success.json` in WireMock's native stub format (status, headers, body).

## Test-domain support

`Outpost_Mock_Server_Filter::REWRITABLE_HOSTS` (PR #56) listed only production upstream hosts (Notion, Oura, RWG, etc.). Integration tests for generic primitives (Og_Inbound, RSS extractor, etc.) need a test-only host that the filter rewrites cleanly. The companion infrastructure PR for CI integration should extend that list with `example.test` and `*.outpost-fixture.test` so test fixtures can use stable host names.

## What's been migrated so far

Nothing in this PR. This is the inventory + plan, not the migration.

The PR companion to this doc is C1's honest scope: the stubs stay skipped, the path to migrating them is now mapped.
