# G99 stub-migration readiness audit

Audit of every remaining integration-test stub cluster against the readiness check (a/b/c/d). Produced 2026-05-08 as Phase 1 of the overnight queue. Drives Phase 3 cluster picks.

## Readiness criteria recap

- **(a)** SUT's declared extractor exists in `Outpost_Source_Registry` (concrete, not stub). N/A for non-source clusters.
- **(b)** All branches of the dispatch chain reach concrete code (no `Extractor_Not_Implemented_Exception` paths, no missing dependencies).
- **(c)** Test docblocks describe current SUT behavior, not pre-shipping speculation.
- **(d)** Hit hosts are in `Outpost_Mock_Server_Filter::REWRITABLE_HOSTS` (or test is dispatch-only with no fetch).

## Cluster classification

| Cluster | Stubs | Status | Failing check / reason |
|---|---|---|---|
| `RouteHandlerIntegrationTest` | 1 | **ready** | Tests `Outpost_Route_Handler::register_rewrite_rules` + `flush_rewrite_rules` against WP_Rewrite. A2 shipped; concrete; no fetches. |
| `MicropubPhotoWriteShapeTest` | 2 | **ready** | F3 `Outpost_Micropub_Bridges::apply_photo_alt_text` bridge. Test name `attachment_alt_text_persists_independent_of_activitypub` confirms AP plugin not required. Concrete; no fetches. |
| `CompanionActivityPubPassthroughTest` | 4 | **blocked-on-platform** | Tests AP plugin's transformer reading `_wp_attachment_image_alt`. ActivityPub plugin NOT in `.wp-env.json` plugins list (only `indieauth` + `micropub` ship today). Add AP to wp-env.json to unblock — production-config change. |
| `PreviewSourceDispatchTest` | 4 | **blocked-on-product** | Stub #2 `explicit_source_id_unknown_returns_501` asserts WP_Error `extractor_not_implemented` for Source_Unknown's extractor. F16 made `og_tags` concrete; the SUT now returns extracted shape, not 501. (c) violation. Other 3 stubs are clean — partial migration like cluster #3, needs user decision. |
| `ShortcutDispatchTest` | 5 | **blocked-on-platform** | Gotcha #10: `Outpost_Shortcut_Controller::read_json_payload()` (`includes/sources/class-shortcut-controller.php:101`) hard-codes `file_get_contents('php://input')` with no test seam. PHPUnit-CLI `php://input` is empty; stream-wrapper override risky (replaces all `php://*`). Needs production-side body-source seam. |
| `SyndicationCaptureFlowTest` | 6 | **ready** | F12 capture flow. REST endpoints use `WP_REST_Request::get_param()` — body injectable via `set_body()`. Multiple admin/REST surfaces, all concrete. |
| `ManualShareIntegrationTest` | 9 | **ready** | F9 `Outpost_Manual_Share_Controller`. REST endpoints use `get_param()` — body injectable. 10 default platforms shipped; `chips_for_mode()` concrete. |
| `IosShortcutConnectionFlowTest` | 10 | **ready** | FX REST endpoint at `includes/class-ios-shortcut-rest-controller.php:48` uses `register_rest_route` with `args` definition — WP REST infrastructure parses body. **NOT** the F6 `/post/shortcut` blocked controller. Admin pages testable directly. |
| `AppearanceSettingsFlowTest` | 13 | **ready** | FY theming. REST controller uses `$request->get_json_params()` (`class-appearance-rest-controller.php:168`). Admin settings page + REST + transient invalidation all concrete. |
| `G4bAppleMusicIntegrationTest` | 4 | **blocked-on-platform** | Gotcha #4: `music.apple.com` not in REWRITABLE_HOSTS. 3 of 4 stubs hit `music.apple.com` for the OG primary fetch via `Outpost_Og_Inbound::fetch`. Same blocker family as Notion deferred stubs. Add `music.apple.com` to REWRITABLE_HOSTS to unblock — production-config change. |
| `NotionShareTargetPreviewTest` (deferred 3 of 6) | 3 | **blocked-on-product** | Issue #69 still open — disconnected Notion preview UX decision (501 today vs og:title fallback). Carries gotcha #4 + the dispatch-chain divergence from cluster #3. |

## Summary

- **Ready (5 clusters, 31 stubs):** RouteHandler · MicropubPhotoWriteShape · SyndicationCaptureFlow · ManualShareIntegration · IosShortcutConnectionFlow · AppearanceSettingsFlow
- **Blocked-on-platform (3 clusters, 13 stubs):** CompanionActivityPubPassthrough (AP plugin) · ShortcutDispatch (gotcha #10) · G4bAppleMusic (gotcha #4)
- **Blocked-on-product (2 clusters, 7 stubs):** PreviewSourceDispatch (3 of 4 ready, 1 stale-docblock) · NotionShareTargetPreview deferred 3 (Issue #69)

Total remaining: **51 stubs** across 10 clusters. Of those, **31 stubs are migrable today** without further code or product decisions.

## Phase 3 ordering (ascending stub count, ready clusters only)

| Order | Cluster | Stubs |
|---|---|---|
| 1 | `RouteHandlerIntegrationTest` | 1 |
| 2 | `MicropubPhotoWriteShapeTest` | 2 |
| 3 | `SyndicationCaptureFlowTest` | 6 |
| 4 | `ManualShareIntegrationTest` | 9 |
| 5 | `IosShortcutConnectionFlowTest` | 10 |
| 6 | `AppearanceSettingsFlowTest` | 13 |

The 4-PR overnight cap means clusters 1–4 above (RouteHandler · MicropubPhotoWriteShape · SyndicationCaptureFlow · ManualShareIntegration) fit. Clusters 5–6 land in a follow-up session.

**This audit is consumed by Phase 3 unless gotcha #10 (discovered during cluster #7 ShortcutDispatch attempt) hard-stops further migration work.** See `docs/dev/integration-test-gotchas.md` for gotcha #10's full description.
