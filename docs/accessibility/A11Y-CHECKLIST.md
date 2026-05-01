# Accessibility Checklist (WCAG 2.1/2.2 Level AA)

Per-surface accessibility audit and forward-looking checklist for Outpost. Pairs with `docs/security/PHP-SURFACE-CHECKLIST.md` and `docs/performance/PERFORMANCE-CHECKLIST.md`.

## Status at v0.1.8

The PWA shell + Preact components meet most WCAG 2.1 AA criteria today. One actionable fix landed (lang attribute); the remainder are forward-looking gates for surfaces that haven't shipped yet (composer modes in Phase C, real-device screen reader testing, color contrast verification when A3 ships token defaults).

### What was applied at v0.1.8

- ✅ **WCAG 3.1.1 Language of Page** — `<html lang>` now reflects the WordPress site locale via `get_locale()` (BCP 47 format with `_` → `-` substitution). Previously hardcoded `"en"` regardless of site language. Applied to `render_shell()`, `render_install_prompt()`, and `render_host_unmet_prompt()`.

### What was already correct

- ✅ **WCAG 1.3.1 Info and Relationships** — semantic landmarks (`<main>` element on every shell render path), proper heading hierarchy (single `<h1>` per surface), `<label for>` ↔ `<input id>` association, `aria-labelledby` on cards.
- ✅ **WCAG 2.1.1 Keyboard** — all interactive elements are real `<button>` / `<a>` / `<input>` / `<textarea>` (no `<div onClick>`).
- ✅ **WCAG 2.4.7 Focus Visible** — `:focus { outline: 2px solid var(--outpost-focus, currentColor); outline-offset: 2px; }` on every interactive element.
- ✅ **WCAG 2.5.5 Target Size** — buttons and form inputs use `min-height: 2.75rem` (44px), meeting Level AAA.
- ✅ **WCAG 4.1.2 Name, Role, Value** — `aria-live="polite"` wraps the dynamic AuthCallback states (signing-in / signed / error). `role="alert"` on `<div class="outpost-error">` for assertive error announcements.
- ✅ **WCAG 4.1.3 Status Messages** — NoteForm's posted/error states use `aria-live="polite"` and `role="alert"` respectively.
- ✅ **WCAG 3.3.2 Labels or Instructions** — every form input has a visible label.

## Forward-looking — surfaces that haven't shipped

### A3 — Color contrast verification

**WCAG 1.4.3 Contrast (Minimum, Level AA)** requires 4.5:1 for normal text, 3:1 for large text and UI components. Outpost can't guarantee this until A3 ships token defaults — currently `var(--outpost-*, theme-fallback)` defers entirely to the theme, and the cascade may not produce contrast-compliant results.

**Required when A3 ships:**

- [ ] Verify `--outpost-primary-bg` / `--outpost-primary-fg` token defaults meet 4.5:1 against each other.
- [ ] Verify `--outpost-input-bg` / `--outpost-input-fg` defaults meet 4.5:1.
- [ ] Verify `--outpost-error-bg` / `--outpost-error-fg` defaults meet 4.5:1 (errors are critical text).
- [ ] Verify focus ring color (`--outpost-focus`) has 3:1 contrast against adjacent backgrounds.
- [ ] Document the *theme contract*: themes that override these tokens MUST themselves meet 4.5:1, since Outpost's structural CSS only enforces non-paint defaults. Captured in `THEME-INTEGRATION.md` (Phase J).

A3-REQUIREMENTS.md should add a contrast verification item.

### Phase C — Composer mode focus management

**WCAG 2.4.3 Focus Order** requires that tab order matches the visual reading order. When Phase C lands the mode tabs (Note → Reply → Photo → Listen group → Article):

- [ ] Tabs implement WAI-ARIA's [tabs pattern](https://www.w3.org/WAI/ARIA/apg/patterns/tabs/): `role="tablist"` on the container, `role="tab"` on each tab button, `role="tabpanel"` on each mode body, `aria-controls`/`aria-labelledby` linking them.
- [ ] Arrow-key navigation between tabs (left/right arrows move focus, Tab moves out of the tablist).
- [ ] When a mode is selected, focus moves to the first interactive element of that mode's panel (or stays on the tab — both are valid; pick one and document).
- [ ] When a mode is opened from the More pull-out, focus moves to the mode's content; closing the pull-out returns focus to the trigger.

### Phase D — Reduced motion + offline announcements

**WCAG 2.3.3 Animation from Interactions (AAA, but worth supporting):**

- [ ] Respect `prefers-reduced-motion: reduce` for any transitions added in Phase D (composer mode switches, offline-queue retry indicators, "Posting…" → "Posted" transition).

**Offline queue announcements:**

- [ ] When a post is queued for retry due to offline state, announce via `aria-live="polite"`. When the retry succeeds, announce success. Match the existing pattern from NoteForm's posted/error states.

### Phase E — Share-target + bookmarklet

**WCAG 2.4.4 Link Purpose (In Context):**

- [ ] Bookmarklet labels are descriptive (`"Bookmark this page in Outpost"`, not `"click here"`).
- [ ] When the share-target route receives a payload from another app, the rendered "Sharing into Outpost" UI labels the action clearly so a screen reader user knows what's happening.

### Phase G — CI + automated testing

- [ ] Add a Playwright + axe-core CI job. Extends the existing `test-js` job with a `test-a11y` matrix entry that runs against the production build on a headless browser.

```typescript
// pwa/tests/e2e/a11y.spec.ts (planned)
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('login screen passes axe', async ({ page }) => {
  await page.goto('/post/');
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations).toEqual([]);
});
```

- [ ] Run pa11y CLI as part of the deploy ritual against staging URLs. Catches regressions that unit tests can't.

### Phase J — Real-device screen reader testing

**Test matrix (sign off before WordPress.org submission):**

- [ ] iPhone Safari + VoiceOver — primary user platform (per Courtney's smoke-test workflow memory)
- [ ] macOS Safari + VoiceOver — desktop equivalent
- [ ] Android Chrome + TalkBack — secondary
- [ ] Windows Chrome + NVDA — desktop secondary

For each, walk the SMOKE-TESTS.md scenarios and verify:
- Login flow announces the form labels and the "Sign in" button purpose
- Auth-callback transient states announce ("Signing you in", then "Signed in")
- NoteForm announces posted state with the new post URL as a focusable link
- Sign out announces the return to the login screen

## Cross-cutting patterns

**No skip-navigation needed (yet):**
- The PWA shell has no chrome above the main content. Skip nav is structural for sites with header + sidebar; Outpost is single-purpose. If Phase C adds persistent nav (e.g. an account menu), revisit.

**Heading hierarchy:**
- Single `<h1>` per surface (the card title). All other content is `<p>` or `<dl>`. No skipped levels.
- Phase C's composer modes will need `<h2>` for mode labels nested under the form's `<h1>`.

**Alt text:**
- Outpost ships no images at v0.1.8. When Phase C/D's photo upload pipeline lands, alt text becomes structurally required (per CLAUDE.md "Standards" section: "Required alt text on photos. Structural, not configurable."). The Photo mode's textarea/input must enforce non-empty alt before allowing submission, with a "decorative" toggle that submits `alt=""` for purely decorative images.

**Semantic HTML:**
- Use `<button>` for actions, `<a>` for navigation. `<a class="outpost-button">` is acceptable when it's a navigation link styled as a button (e.g. "Start over" goes to `/post/`).

**Color-independent meaning:**
- WCAG 1.4.1 — never convey meaning by color alone. Errors get text + an `<div class="outpost-error" role="alert">` boundary, not just a red color shift. ✓ Already correct.

## Validation routine

Before any session that touches user-facing rendering closes:

```bash
# Manual spot-checks, current state
cd ~/projects/outpost
composer lint        # PHPCS clean
composer test        # PHPUnit clean
npm test             # Vitest clean

# Manual: open /post/ on iPhone Safari + VoiceOver
# Walk the login + post flow, verify announcements
```

When the Playwright + axe CI job lands (Phase G), CI gates regressions automatically.

---

*Last reviewed: 2026-05-01 (v0.1.8). Update on every user-facing rendering change.*
