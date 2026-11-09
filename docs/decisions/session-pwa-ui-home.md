## Decision — Home launcher, footer Home link, and a per-person start screen

Recorded 2026-11-08. Base: `main` at `a12e266` (1.0.24 plus the docs rewrite). Extends [the PWA UI appearance decision](session-pwa-ui-appearance.md) and [the integrated implementation brief](../product/pwa-ui-2026-11-07/implementation-brief.md), sections 3, 6, 7, and 8. Nothing in the approved appearance work changes: Outpost Default, Use Site Theme, Light / Dark / System, the outline signpost, and the seven tabs.

### Home is a destination inside the composer, not a second router

`ComposerTabs` holds one more piece of state, `view`: `'home'`, `'tabs'`, or `null` while the start screen is unknown. `active` still names the selected tab, and the WAI-ARIA tab strip, its keyboard model, and the seven panels are unchanged.

- On Home, the tab strip and every panel carry `hidden`. The panels stay mounted, as session C0 decided, so text, photos, and picker state survive a trip Home. Nothing unmounts.
- A Home card sets `active`, shows the tabs, and moves focus to the selected tab, so a screen reader announces "Reply, tab, selected, 2 of 7".
- The **← Home** link sets `view` to Home and moves focus to the Home heading (`tabIndex=-1`).
- No URL changes. A standalone iOS PWA has no back button, and the share-target dispatcher already owns the query string (`?mode=`, `?picker=`). History entries for Home would make Android's back button step through tabs before leaving, which nobody asked for.

Rejected: a client router or hash routes (a second navigation system, and a refresh would reopen a tab instead of the start screen); unmounting the composer on Home (drops photos, which aren't in drafts).

### Footer link

One button, "← Home", in a `<nav aria-label="Outpost">` after the panels and scenery. It's in the page flow, never `position: fixed` or `sticky`, so it can't cover Publish, a focused field, or the on-screen keyboard (WCAG 2.4.11). The composer's bottom padding already includes `env(safe-area-inset-bottom)`. It duplicates none of the tab strip. Home has no footer link.

### Drafts on the way Home

Going Home discards nothing, because nothing unmounts. Home reads the saved drafts (`draft_modes()` in `pwa/src/lib/drafts.ts`) when it opens and again 700 ms later, after a save that was still debouncing lands, and labels each card that has one "Unsent draft". The label is text and part of the card's accessible name, so color doesn't carry it. A photo attached without any typed text has no draft entry, so its card shows no label. The photo is still attached in the Photo tab.

### Start screen preference

| | |
| --- | --- |
| Values | `home`, `note`, `reply`, `photo`, `listen`, `life`, `recipe`, `about`. These are the PWA's tab ids, so Post is `note` and Doing is `listen`. |
| Default | `home`, for everyone without a stored value, new and existing users alike |
| Storage | user meta `outpost_start_screen` (`Outpost_Start_Screen`) |
| Transport | `start_screen` on `POST /wp-json/outpost/v1/appearance/tokens`, beside `mode_preference` and `style_source` |
| Device copy | `start` in the `outpost.appearance.v1` localStorage entry, with the same `pending` reconciliation |

It rides the appearance endpoint instead of a new one because it needs exactly what that endpoint already has: bearer auth with the token in the body, the scope gate, `no-store` responses, a device copy, and offline "applied here, sent later" reconciliation. A second endpoint would duplicate all of it. The class comment in `Outpost_Start_Screen` records why it lives there.

Existing users get Home too, not Post. Courtney asked for "Home (default)", and the launcher is the point of this change. Someone who wants the 1.0.24 behavior picks Post once and it follows them to every device. The style-source precedent (keep upgraders on Site Theme) was about paint people had already seen. This is about one extra tap, which Reset and the preference make cheap to undo.

It isn't `default_post_variant`. That site-wide setting picks the style the Post tab starts on and is unchanged.

### Which screen opens

`initial_screen()` in `pwa/src/lib/start-screen.ts`, highest first:

1. Sign-in callback and recovery. `index.tsx` routes `/post/auth/callback` before the composer mounts. An expired sign-in is a banner on whichever screen opens, so it never blocks or replaces one.
2. A share-sheet, bookmarklet, or iOS Shortcut launch: `peek_share_target()`. A shared photo opens Photo and a shared link keeps the tab and variant the dispatcher chose, whatever the saved start screen says.
3. Navigation already made. The composer decides once. A preference that arrives late, or changes during the session, never moves the person.
4. The saved start screen.
5. Home.

A device that has a copy of the preference (every launch after the first) opens the right screen on the first render: no redirect, no flash. A first launch on a device waits for the site's answer and shows "Opening Outpost…" in a live region. It waits at most 1.5 s (`START_SCREEN_WAIT_MS`), then opens Home. The tab panels are already mounted underneath, so a share intake or draft restore isn't delayed.

The manifest has no `shortcuts` entries. "Incoming shortcut" here means the iOS Shortcut bridge and bookmarklets, which arrive through the share-target dispatcher (step 2).

### About

Four sections, in order: **Connection** (runtime facts, Check connection again or Sign in again, Sign out of this device, Diagnostics), **Preferences** (the same `PreferencesPanel` the header sheet shows), **Help and documentation** (Getting started, Plugin documentation, Sharing and shortcuts, Troubleshooting, Privacy and data, plus the share-sheet and bookmarklet guides), and **Project** (GitHub repository, Report an issue, version and credits from `readme.txt`, and the IndieWeb reference disclosure). No profile fields and no publish action, as before.

Both copies of the preferences are in the document at once, because the About panel stays mounted while hidden. Every id and radio-group name carries an `idPrefix`; a test checks that every id in a page holding About and the sheet is unique.

No Roadmap link: the repository has no maintained roadmap page or milestone set to point at. The mockup's row was illustrative.

### Sign out moved

Sign out sat in the Post form beside the publish button, on the most-used screen, and it clears the device's drafts. It's now "Sign out of this device" in About › Connection, with a line saying what it removes. The behavior (`pwa/src/lib/sign-out.ts`) is unchanged: token, drafts, and appearance copy cleared, then a reload.

### Found and fixed on the way

- The uninstaller didn't delete `outpost_appearance_source` (user meta) or `outpost_appearance_schema` (option), both added in 1.0.24.
- `useAppearance` dropped the first of two changes made before a re-render, for example a start-screen pick followed by a mode pick in the same tick. `change()` now builds on the change it just made.
- `/appearance/tokens` stored a valid `style_source` before rejecting a bad `mode_preference` in the same request. It now validates every field first.
- The note-mode test "keeps working when storage refuses writes" spied on `Storage.prototype.setItem`, which happy-dom's `localStorage` doesn't route through. It passed only because the sign-out test before it disabled drafts for the page. It spies on the `localStorage` instance now.

### Tests

PHP: `tests/theming/StartScreenTest.php`, start-screen cases in `tests/theming/AppearanceRestControllerTest.php`, and uninstall assertions in `tests/integration/UninstallTest.php`. PWA: `pwa/src/lib/start-screen.test.ts`, start-screen cases in `appearance.test.ts` and `use-appearance.test.tsx`, `sign-out.test.ts`, the Home and start-screen block in `composer-tabs.test.tsx`, and the Preferences and About cases in `settings-and-about.test.tsx`.
