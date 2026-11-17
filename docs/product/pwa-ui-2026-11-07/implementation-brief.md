# Outpost PWA implementation brief

Implement the approved illustrated Outpost composer with Outpost Default styling, an optional Site Theme palette, independent Light/Dark/System controls, a Home launcher with a per-person default start screen, and an About tab for connection, preferences, and help.

> **Revision 2026-11-08.** The Home launcher, footer Home link, Default start screen preference, and four-section About were merged into this brief (sections 3, 6, 7, 8, 10, and 12) rather than written as a separate feature. The approved appearance decisions are unchanged. The added design reference is [home-launcher-mockup.webp](home-launcher-mockup.webp); the decision record is [session-pwa-ui-home.md](../../decisions/session-pwa-ui-home.md); a review of the mockup against the shipped tabs is in [mockup-review-2026-11-08.md](mockup-review-2026-11-08.md).

## 1. Instructions, scope, and sources

You are Claude Code, the implementation owner for `courtneyr-dev/outpost`. Inspect, plan, implement, verify, and document the work. Make routine implementation choices within the approved design. Work on a feature branch. Don't merge, release, tag, deploy, change deployment repositories, or publish posts to a real site without Courtney's explicit instruction.

Read `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`, `docs/decisions/session-fy-theming.md`, relevant accessibility/security decisions, applicable local skills, tests, and current open work before editing. Respect other branches and uncommitted changes. Follow the repository's design/executor split. Claude Code owns implementation decisions; ChatGPT can review the artifacts described in section 11.

This handoff inspects main at commit `d79277f67000199245bfcfe344684b7d0aa167cd`. Reconcile drift before implementation. The design reference is `docs/product/pwa-ui-2026-11-07/theme-adaptive-outpost-ui-mockup.png`. Read its adjacent `README.md` and `outpost-signpost-outline.svg`. The PNG is the final outline revision from the ChatGPT conversation [Improve Outpost PWA UI](https://chatgpt.com/c/6aec9ec4-6664-83ea-ba10-32826873e408).

The PNG supplies visual direction. This brief, current runtime capabilities, accessibility, and canonical SVG geometry take precedence over generated labels, truncated option lists, and decorative details. Don't use a screenshot as application markup or extract form text from its pixels.

## 2. Resolve the styling contract explicitly

The current Hard Contract says the theme owns every paint value. Courtney now authorizes Outpost Default as the shipped design and Site Theme as an option. Record this deliberate amendment in a new decision and surgically update the conflicting wording in `AGENTS.md`, `CLAUDE.md`, `README.md`, and relevant integration docs as part of implementation. Preserve the historical FY decision and explain what supersedes its default-selection rule.

The amended contract is: Outpost owns layout, interaction, accessibility, and default design tokens. The selected style source supplies paint through `--outpost-*` tokens. Components never hardcode paint. Site Theme remains portable and optional. Preserve B6, token parity, shell isolation, and all seven public `--plain` treatment opt-outs.

Don't replace this plugin's approved violet/paper/orange design with the separate Outpost & Co. hosting company's forest-green marketing kit. Don't make another site's palette or fonts a plugin dependency.

## 3. Appearance model

Expose two independent choices in the PWA settings sheet. Provide named controls with a programmatically selected state. The header may include a compact mode shortcut and a settings button; both must reflect the same state.

| Choice | Options | Behavior |
| --- | --- | --- |
| Style source | Outpost Default, Use Site Theme | Selects the color and type source without changing brightness mode |
| Appearance | Light, Dark, System | Maps to existing stored `day`, `night`, `system` values |

A third, independent preference sets the screen a normal launch opens to:

| Choice | Options | Behavior |
| --- | --- | --- |
| Default start screen | Home (default), Post, Reply, Photo, Doing, Life, Recipe, About | Opens on a normal launch only. Stored as user meta `outpost_start_screen` with the PWA tab ids (`home`, `note`, `reply`, `photo`, `listen`, `life`, `recipe`, `about`) and carried as `start_screen` on the same `/appearance/tokens` request, with the same device copy and offline reconciliation. Not the site-wide `default_post_variant`, which picks the style the Post tab starts on. |

Startup precedence, highest first: (1) the sign-in callback and recovery flows; (2) a share-sheet, bookmarklet, or iOS Shortcut launch, which opens the tab its content belongs in (a shared photo opens Photo; a shared link keeps the dispatcher's tab and variant); (3) navigation the person already made, because the screen is decided once and a late or changed preference never moves anyone off a draft; (4) the saved start screen; (5) Home. A device holding a copy of the preference opens the right screen on first render. A first launch on a device shows "Opening Outpost…" in a live region while it asks the site, for at most 1.5 seconds, then opens Home. Changing the preference never navigates. Light/Dark/System and the style source stay independent of it.

New users start with Outpost Default + System. Preserve existing saved `outpost_appearance_mode` and explicit token overrides. For upgrades with no style-source field, migrate existing users to Site Theme to preserve their prior behavior. Document and test the new-user/upgrade distinction; don't infer it from whether a user happens to have a mode value. A schema/migration marker can distinguish existing installations. A fresh author on a new install gets the new default.

Extend the existing per-user appearance controller and REST path. Reuse `outpost_appearance_overrides`. Select one canonical per-user style-source field and document its values. Keep preference data out of shared caches. Non-secret local preference data may support first paint and offline use, but bearer tokens remain in encrypted IndexedDB.

Resolution order is explicit user token override, selected source, accessible Outpost fallback. In Outpost Default, theme colors and typography don't silently override the chosen design. In Site Theme, resolve supported semantic theme values and fall back per token. Run contrast checks on the final foreground/background pairs after resolution.

Light always renders the light scheme; Dark always renders the dark scheme. System follows `prefers-color-scheme` and responds while the app is open. Maintain both the selected preference and effective scheme. Apply the matching `color-scheme` for native controls. Prevent an incorrect first-paint flash and avoid competing body classes.

A deliberate PWA choice outranks an optional site appearance bridge. A bridge can provide an initial suggestion when no PWA preference exists; it must not overwrite the displayed selection. Audit the shell filters before assuming a bridge exists. Don't hardcode `courtneyr-theme` in the generic plugin. Persist successful saves, report failed saves, and show an honest device-only state when offline. Reconcile server and device state without overwriting a newer user choice. Verify multi-tab changes, storage denial, logout, and another user's session.

## 4. Default design and theme integration

Match the PNG's warm paper light scheme, violet ink, restrained orange actions, violet dark scheme, compact composer, and mountain/pine decoration. Keep text and form areas flat and readable. Don't reproduce the raster's gradients, glow, tiny text, or ornamental border treatments. Decorative scenery sits outside the writing area, doesn't convey status, and doesn't move. Use reusable local assets with a documented origin and license. Don't add remote tracking or runtime image/font dependencies.

Start from existing plugin typography tokens: IBM Plex Sans for UI, Newsreader/Source Serif Pro display fallbacks, and IBM Plex Mono for code. Verify which fonts actually load in the standalone shell and their licensing. Keep display typography in headings and the wordmark; use readable UI text for labels and controls. Site Theme can resolve its own supported body/display fonts. Don't infer a font family from generated lettering.

Audit and extend these existing paths rather than adding a second theme engine:

- `includes/theming/class-theme-json-reader.php`, `class-token-resolver.php`, `class-contrast.php`, and `class-mode-controller.php`.
- `includes/class-appearance-rest-controller.php` and `includes/admin/class-appearance-settings-page.php`.
- `includes/class-pwa-shell.php`, its `outpost_shell_*` filters, and `pwa/src/index.tsx`.
- `pwa/src/styles/tokens.css`, `styles/outpost-tokens.css`, `pwa/src/styles/structure.css`, and `bin/check-tokens-parity.mjs`.

The inspected shell prints an explicit asset list and mode class. Its asset-registration path doesn't itself call `Outpost_Token_Resolver::to_css()`. Trace every call and any theme integration before declaring a runtime failure. Prove that resolved tokens reach the actual standalone document, including an authenticated PWA using bearer auth when PHP sees an anonymous shell request. Don't expose private tokens in cached HTML to solve this.

Use supported WordPress resolved Global Styles/theme APIs for parent/child theme settings and user edits. Prefer semantic background/text/link/button styles and explicit integration tokens to guesses based on palette slug names. Resolve CSS preset references safely. An arbitrary `primary` color isn't necessarily body text. Classic themes, incomplete palettes, missing fonts, and absent dark variations must work through documented fallbacks. Don't load the theme's whole frontend asset queue.

Map surface, elevated surface, text, muted text, links, fields, borders, selected tabs, chips, primary/secondary buttons, focus, error/warning/success, disabled controls, and logo color to existing or justified new semantic tokens. Keep token files synchronized. Extend pairwise contrast checks beyond the resolver's current text/text_secondary/accent against surface checks. Check button text against button fill, placeholders against fields, and focus against neighboring surfaces.

Use a theme's explicit light/dark values when supported. For a missing scheme, adapt through the existing contrast helper or use documented Outpost fallback pairs. Don't invert every color or assume every style variation is a dark mode. Cache keys/invalidation must include style source, effective scheme, user, overrides, theme identity, and Global Styles changes. A theme version alone doesn't capture an editor changing a palette.

## 5. Signpost identity

Canonical source: `assets/icons/outpost-icon.svg`, blob `3f3a5c598558b3c3fea02fc7c12cd3e48bd0d1ae` at the inspected commit. Its 256×256 viewBox contains a pole at x=121/y=44, a foot at x=97/y=200, a right-pointing upper board, and a left-pointing lower board.

Use the adjacent outline derivative for in-app identity. It preserves those four shapes and their source coordinates, removes the green tile and decorative inset frame, and renders `fill="none"` with `stroke="currentColor"`. Its mask hides the pole behind the boards and foot. When inlining multiple instances, make mask IDs unique per instance.

Bind the logo color to a semantic `--outpost-*` token. Use violet in default light, a contrasting light outline in default dark, and a tested theme foreground/accent in Site Theme. Prefer foreground if the accent fails contrast. Keep the background transparent. Don't substitute a compass, mountain badge, generic icon-library signpost, or an AI redrawing. The mountains are separate decoration.

Preserve `outpost-icon.svg`, PNG manifest icons, maskable icons, and Apple touch icons as the fixed install identity in this change. The in-app theme choice doesn't regenerate them. An adjacent Outpost wordmark can make the inline mark decorative with `aria-hidden`; a standalone mark needs an accessible name. Compare geometry to the pinned canonical source and inspect at 24, 32, 48, and 64 CSS pixels in both schemes and forced colors.

## 6. Home and all seven destinations

### Home launcher

Home is a launcher, not a form. It's a destination inside the composer, not a second router: the composer adds a `view` (Home or tabs) beside the existing selected tab, keeps the WAI-ARIA tab strip and all seven panels mounted, and hides them on Home. No URL changes.

Home shows a compact connection line from runtime state (the same states as About, never a hard-coded "Connected"), the heading "What would you like to post?", six cards, an "Everything each tab can post" disclosure, and an About row. Each card is one native button with an icon, a title as its accessible name, and a short description as its accessible description. A card whose tab holds a saved draft adds the text "Unsent draft" to its name.

| Card | Opens | Inventory (read from the mode files' variant tables) |
| --- | --- | --- |
| Post | `note` | Article, Note, Status, Aside, Quote |
| Reply | `reply` | Reply, Like, Favorite, Repost, Bookmark, RSVP, Follow, Wishlist, Tag, Acquisition, Issue |
| Photo | `photo` | Single photo and gallery |
| Doing | `listen` | Listen, Watch, Read, Play, Game, Jam, Checkin, Eat, Drink, Exercise, Craft, Event, Review, Video, Audio |
| Life | `life` | Mood, Weather, Sleep, Trip, Itinerary, Question |
| Recipe | `recipe` | Structured recipe: title, ingredients, instructions, yield, total time, notes |

Two columns on phones, three from 40rem, one only below 17.5rem (very large text). Choosing a card opens its tab and moves focus to the selected tab. Paint comes from the selected style source's tokens like every other surface; the scenery stays decoration.

### Existing publishing behavior

Preserve runtime capability gates, property mapping, validation, drafts, and submission semantics. Read `pwa/src/components/composer-tabs.tsx`, the mode components under `pwa/src/components/modes/`, `pwa/src/lib/composer-config.ts`, shared media/voice/drawer/More components, and their tests.

| Destination | Complete inventory to preserve |
| --- | --- |
| Post | Article, Note, Status, Aside, Quote |
| Reply | Reply, Like, Favorite, Repost, Bookmark, RSVP, Follow, Wishlist, Tag, Acquisition, Issue |
| Photo | Single photo and gallery workflows |
| Doing | Listen, Watch, Read, Play, Game, Jam, Checkin, Eat, Drink, Exercise, Craft, Event, Review, Video, Audio |
| Life | Mood, Weather, Sleep, Trip, Itinerary, Question |
| Recipe | Structured recipe workflow |
| About | Connection, authentication, help, documentation, source, and version |

The PNG doesn't show every variant or field. Use current code as the inventory. Preserve existing internal IDs such as `note` for Post and `listen` for Doing. Keep Article-first ordering if current code still specifies it. Preserve companion-dependent features rather than showing unavailable controls as working.

Post keeps title rules, body, quote source/citation, voice/Markdown affordances, and variant-specific requirements. Reply keeps target URL context, all 11 types, RSVP choices, XFN when available, and existing Micropub properties. Photo keeps camera/file selection, ordering/removal, captions, required alt text or an explicit decorative choice, upload progress/error/retry, EXIF privacy handling, and optional media semantics.

Doing keeps all 15 kinds, lookup/search, kind-specific metadata and ratings, optional photos where supported, and distinct video/audio handling. Life keeps all six kinds and their structured fields. Recipe keeps ingredients, steps, yield, time fields, notes, photo support, and h-recipe serialization. Don't replace structured inputs with one unparsed textarea because the mockup draws a list.

Preserve More options, categories/tags, syndication defaults, companion capability gates, SEO/XFN, format selection, draft/queue handling, share targets, bookmarks, post-success links, and manual-share behavior. Don't add ATmosphere chips or claim companions support features the runtime doesn't expose.

## 7. Mobile interaction and state

Keep a compact identity/status header, clear active destination, readable option controls, persistent labels, and a primary publishing action. Use an accessible horizontally scrollable tab strip with a visible overflow affordance on small screens. Keep existing arrow/Home/End keyboard behavior and correct tab/tabpanel relationships; don't duplicate navigation into inaccessible copies. Keep selected tabs in view.

Replace colliding decorative radios with native radio semantics and a label layout that wraps without overlap. Long inventories may use a compact picker or disclosure, but every option stays discoverable and selected values stay visible. Use fieldsets/legends and full-label hit targets. Preserve public `--plain` opt-outs.

Size textareas for the task and let them grow without consuming the whole viewport. Keep font sizes at least 16 CSS pixels for phone inputs. Aim for 44×44 CSS pixel targets; meet WCAG's 24×24 minimum and spacing exceptions where relevant. Avoid page-level horizontal scroll at 320 CSS pixels. Support 200% text and 400% zoom/reflow, long labels, safe areas, landscape, virtual keyboards, and larger desktop widths.

Every composing tab and About ends with one footer link, "← Home", in a `<nav>` after the panels and scenery. It's in the page flow, never fixed or sticky, so it can't cover Publish, a focused field, or the keyboard; the composer's bottom padding already clears `env(safe-area-inset-bottom)`. It returns Home and moves focus to Home's heading. Home has none. It doesn't repeat the tab strip.

Going Home never discards a draft or an attached photo, because the panels stay mounted. Home re-reads saved drafts when it opens and labels those cards; a photo attached without typed text has no draft entry but stays attached in Photo.

Keep publish/save controls distinguishable and outside the keyboard's obstruction. A sticky toolbar must never cover the focused control or final field. Give icon-only settings, save, microphone, and close controls accessible names. Don't show "Draft saved" until storage succeeds. Keep useful composition and media state through navigation, appearance changes, retries, and reauthentication. Never auto-publish after sign-in or reconnect.

Differentiate loading, empty, offline, network timeout, 401/expired sign-in, 403/capability/nonce failures, and server failures. Reproduce the companion-options error before attributing it to token expiration. A compact status message offers the right retry or sign-in action while retaining the draft. Use existing persistent live-region patterns for iOS VoiceOver. Keep diagnostics out of ordinary composer copy.

## 8. About means connection, preferences, and help

About has four sections, in this order: **Connection** (site, status, Micropub, IndieAuth, signed-in `me`, Check connection again or Sign in again, Sign out of this device with a note on what it removes, and redacted Diagnostics); **Preferences** (Default start screen, Light/Dark/System, Outpost Default/Use Site Theme, status line, Reset preferences — the same component the header's Preferences sheet shows, with instance-unique ids); **Help and documentation** (Getting started, Plugin documentation, Sharing and shortcuts, Troubleshooting, Privacy and data, plus the share-sheet and bookmarklet guides); **Project** (GitHub repository, Report an issue, version and credits from `readme.txt`, and the IndieWeb reference behind a disclosure). Sharing and shortcuts links to https://courtneyr-dev.github.io/outpost/common-tasks/#share-to-outpost-from-your-phone.


About has no author biography, avatar editing, profile fields, or publish button. Put the connected site's actual address, connection state, relevant Micropub/IndieAuth status, retry/reauthentication controls, and app/plugin version first. Use runtime data; never hardcode the mockup's courtneyr.dev address, green "connected" dot, "Enabled", "Authenticated", or `vX.X.X`. Show unknown/unavailable states when checks haven't run.

Expose detailed endpoints/scopes only in a labeled diagnostics disclosure. Never render bearer tokens or application passwords. Copyable diagnostics must be redacted. Distinguish installed companion availability, endpoint reachability, and authenticated authorization.

Use existing `ExternalLink`, `ShareSheetGuide`, and `BookmarkletList` components where applicable. Preserve useful IndieWeb explanations behind concise links or disclosures. Link to real destinations:

- Documentation: https://courtneyr-dev.github.io/outpost/
- Getting started: https://courtneyr-dev.github.io/outpost/getting-started/
- Troubleshooting: https://courtneyr-dev.github.io/outpost/troubleshooting/
- Privacy and data: https://courtneyr-dev.github.io/outpost/privacy-and-data/
- Source: https://github.com/courtneyr-dev/outpost
- Report an issue: https://github.com/courtneyr-dev/outpost/issues

Verify these destinations during implementation. A roadmap link appears only if the repository has an authoritative destination. Derive credits/version from maintained metadata. Preserve a draft when help opens; announce new-tab behavior with the existing component.

## 9. Accessibility, privacy, and boundaries

Meet WCAG 2.2 AA in the rendered application. Check 4.5:1 normal text, 3:1 large text, and 3:1 meaningful non-text boundaries/states. Never assume the orange/white button pair or pale outline in the PNG passes. Adjust token pairs to pass while retaining the approved design. Use text/icons/borders as well as color for selected, error, connected, and saved states.

Verify keyboard order, visible focus, focus return, sheet focus containment, Escape close, background inertness, field labels/errors, headings/landmarks, meaningful alt text, reduced motion, forced colors, and VoiceOver announcements. Decorative scenery uses empty alt text or `aria-hidden`. Don't reproduce the PNG's "WCAG 2.2 AA" badge as a product claim.

Use The Website Specification's required items for touched categories and applicable recommendations. Record specific URLs in review findings and evidence. Start with [color contrast](https://specification.website/spec/accessibility/color-contrast/), [form labels](https://specification.website/spec/accessibility/form-labels/), [form errors](https://specification.website/spec/accessibility/form-errors/), [keyboard navigation](https://specification.website/spec/accessibility/keyboard-navigation/), [focus indicators](https://specification.website/spec/accessibility/focus-indicators/), [focus not obscured](https://specification.website/spec/accessibility/focus-not-obscured/), [touch targets](https://specification.website/spec/accessibility/touch-target-size/), [image alt text](https://specification.website/spec/accessibility/image-alt-text/), [reduced motion](https://specification.website/spec/accessibility/reduced-motion/), and [forced colors](https://specification.website/spec/accessibility/forced-colors/). Explain any skipped recommendation. Automated scans alone don't establish conformance.

Preserve CSP, escaping/validation, capability and nonce checks, safe CSS serialization, authenticated fetch credential behavior, encrypted token storage, upload privacy, and `/post/` service-worker scope. Don't cache authenticated appearance/config responses publicly. Theme CSS values and incoming review text are untrusted input. Never place secrets, personal drafts, precise locations, or unredacted diagnostics in GitHub artifacts.

## 10. Execution and verification

First reproduce current layouts and appearance behavior on a local/test WordPress site with synthetic content. Measure computed control sizes and trace the token/persistence paths. The six earlier phone screenshots show symptoms; they don't prove root causes. Record observations separately from hypotheses. Don't start by replacing the entire stylesheet or adding a UI framework.

Implementation sequence for the Home revision, as built: start-screen user meta and endpoint field with tests; client preference model and hook; startup precedence function; Home component and composer `view`; footer link; shared Preferences panel in the sheet and About; About sections and sign-out move; styles; docs; captures. The original sequence follows.

Produce a short implementation plan and decision record, then implement in reviewable units: appearance contract/resolver/storage, in-app controls and signpost, shared layout/controls, all mode screens and About, then integration/accessibility verification. Proceed through routine implementation without waiting for another chat's reply.

Write meaningful regression tests for appearance precedence, persistence, upgrade migration, System changes, theme fallback, override isolation, bearer-auth transport, cache invalidation, draft retention, complete option access, and payload preservation. Follow repository test-first rules for production changes. A passing screenshot or build isn't proof of publishing behavior.

Run current equivalents of these checks, confirm scripts still exist, and report actual output:

```text
composer test
composer lint
composer analyze
composer lint:section5
npm run typecheck
npm run lint
npm test
npm run check:tokens
npm run build
npm run test:integration
npm run test:e2e
```

Run documentation checks if you change the docs site. Reuse locked dependencies; don't introduce packages without a task need and registry verification. Bump both version declarations in `outpost.php` when introducing deployable behavior, according to current repository policy. The design-handoff-only commit itself needs no version bump.

Verify both style sources across Light, Dark, System-light, and System-dark. Capture all seven destinations in light and dark, the settings sheet, a representative alternate site palette, classic-theme fallback, and focus/error/offline states. Include 320/390/430 CSS pixel phone widths, tablet/desktop, long labels, zoom, and keyboard-visible layouts. Exercise OS changes while System is active. Use local fixtures to test each configured payload; never publish to a real account as a UI test.

Run automated accessibility checks and manual keyboard/VoiceOver checks. Test actual iOS Safari/installed PWA behavior when device access exists; desktop WebKit emulation doesn't prove it. Mark unavailable device checks as unverified. A feature branch without a PR may have no CI run; don't call CI green until the relevant jobs run and pass. Preserve and investigate failures instead of weakening assertions.

## 11. Collaborate with ChatGPT through review artifacts

ChatGPT can't automatically observe your terminal or private checkout. Collaborate through committed, redacted files and immutable commit links. Under this handoff directory, keep a concise `review-request.md`, `verification.md`, and `review-response.md` when implementation begins. These are implementation deliverables, not prefilled claims that tests already pass.

At the plan checkpoint, put the base/head SHA, scope, appearance precedence/migration decision, source-to-token mapping, touched files, risks, and specific questions in `review-request.md`. At the visual checkpoint, add labeled desktop/phone screenshots and an indexed comparison covering the PNG, canonical logo, settings, seven destinations, and error states. Give every image alt text and explain any numbered marks in text. Use synthetic site/account data.

In `verification.md`, record exact commands, environment, output summaries, test counts, CI URLs/status, measured contrast pairs, manual checks, and every skipped/blocked item. Link screenshots and test evidence to each acceptance criterion. Separate implemented, locally verified, CI-verified, and device-reviewed states.

Give Courtney a ready-to-paste review request containing the branch, immutable file/diff URLs, exact commit SHA, evidence links, and 3-5 bounded questions. If this session has a user-authorized ChatGPT integration, use its documented tools; otherwise the GitHub artifact plus the pasteable request is the handoff. Don't invent a messaging API or claim that ChatGPT reviewed anything before receiving its response. Don't transmit messages to another service without authorization.

Ask ChatGPT to review visual fidelity, canonical signpost geometry, all source/mode combinations, About information architecture, feature omissions, and accessibility evidence. Each actionable finding should name a file/line or screenshot, reproduction steps, impact, and a spec/acceptance reference. Treat its conclusions as advisory. Reproduce each finding independently; record accepted/fixed, rejected-with-evidence, or unresolved findings in `review-response.md`, with commit/test links. Re-review changed areas when new evidence warrants it. Record independent implementation progress while awaiting review.

## 12. Completion criteria and report

The Home revision is ready for review when new people land on Home; each card opens its tab; all seven tabs keep their behavior; drafts survive trips Home; the footer link returns Home from every tab without obscuring composer actions; all eight start screens can be chosen, persist, and open on a normal launch; share and shortcut launches override them; sign-in still works; appearance stays independent; both style sources render Home, a composer, and About; and keyboard, screen-reader names, zoom, reduced motion, and touch targets have evidence.

The implementation is ready for Courtney's review when all seven destinations retain their capabilities, the actual composer renders the approved default design, Site Theme is optional, both sources work with all appearance modes, the signpost matches canonical geometry, About shows runtime connection/help data, drafts survive recovery, and required checks have evidence.

Finish with branch/commit/PR links, before/after screenshots, tests and CI results, accessibility/device-review evidence, material deviations, and known limits. Update existing project decisions/session records for lasting architecture changes; don't rewrite unrelated memory. Keep main, releases, and deployment repositories untouched until Courtney explicitly authorizes shipping.
