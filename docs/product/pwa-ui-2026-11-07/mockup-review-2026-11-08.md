# Home launcher mockup review, 2026-11-08

This review compares the Home launcher mockup with Outpost 1.0.24's shipped tabs and with the Home implementation on branch `pwa/home-launcher`. It lists where this branch follows the mockup, where it deliberately departs, and what each other tab could improve next. Evidence: the 1.0.24 captures in [evidence/](evidence/) and this branch's captures in [evidence-home/](evidence-home/). All captures use local wp-env and synthetic data.

![Generated mockup: Home, Post composer, About, and Preferences in light and dark, plus a Use Site Theme header and a list of key additions.](home-launcher-mockup.webp)

| File property | Value |
| --- | --- |
| File | `home-launcher-mockup.webp` |
| Dimensions | 1199 × 1312 pixels |
| SHA-256 | `2a9e7b0964dedef3732f0bcddd95aa20ce917f7af8df0055688b709d97ffdfe8` |

Like the first mockup, it's a generated image. Its labels, connection dot, `vX.X.X`, and "Draft saved just now" are illustrative, and its pixels aren't token values.

## Required mockups, as real captures

The brief asked for six mockups. Each is now a capture of the running build, which is stronger evidence than a drawing: it shows real copy, real tokens, and the real connection state.

| Required view | Capture | axe (WCAG 2.2 AA tags) |
| --- | --- | --- |
| Home, Light, Outpost Default | [home-light-390.png](evidence-home/home-light-390.png); [320 px](evidence-home/home-light-320.png); [1280 px](evidence-home/home-light-1280.png) | 0 violations |
| Home, Dark, Outpost Default | [home-dark-390.png](evidence-home/home-dark-390.png) | 0 |
| Home, Use Site Theme | [light](evidence-home/home-site-light-390.png), [dark](evidence-home/home-site-dark-390.png) (Twenty Twenty-Five palette) | 0 |
| Composer with the Home footer link | [post-light-390.png](evidence-home/post-light-390.png), [post-dark-390.png](evidence-home/post-dark-390.png) | 0 |
| About: Connection and documentation | [about-light-390.png](evidence-home/about-light-390.png), [dark](evidence-home/about-dark-390.png), [Use Site Theme](evidence-home/about-site-light-390.png) | 0 |
| About: Preferences with Default start screen | in each About capture; [Preferences sheet](evidence-home/preferences-sheet-light-390.png) | 0 |
| Home with an unsent draft | [home-draft-light-390.png](evidence-home/home-draft-light-390.png) | 0 |

Raw results: [home-axe-results.json](evidence-home/home-axe-results.json). An automated scan doesn't establish conformance.

## Where the build departs from the mockup, and why

| # | Mockup | Built | Reason |
| --- | --- | --- | --- |
| 1 | "PUBLISH EVERYWHERE" under the wordmark | The connected site's host | An all-caps tagline is a claim, not information. The host tells you where a post goes. |
| 2 | "Connected to courtneyr.dev ●" strip on every screen | A connection line on Home only | The composer header already shows the host, and About has the full connection card. A green dot alone is color-only (WCAG 1.4.1), so the line uses the same glyph and text states as About. |
| 3 | All seven tabs squeezed into 390 px in small italic | The scrolling tab strip with an overflow cue | Seven labels at that size fall below a readable size. The brief kept the scrolling strip. |
| 4 | House icon inside the start-screen select | A native `<select>` with text options | Native controls get platform pickers on iOS and Android and read correctly in screen readers. |
| 5 | Palette swatch row under Style source | Not built | Swatches with no labels carry meaning by color alone. Proposal 8 below is a labeled version for Use Site Theme. |
| 6 | Roadmap row in About | Not built | The repository has no maintained roadmap page or milestones to link to. |
| 7 | "Outpost vX.X.X · IndieWeb · WordPress" | The running version and the credits from `readme.txt` | The brief bans placeholder versions. |
| 8 | Micropub ✓ and IndieAuth ✓ | Runtime states, including "Not checked yet" | The brief bans hard-coded success states. |
| 9 | Settings reached through About only | The gear opens a Preferences sheet; About › Preferences shows the same panel | The approved header sheet stays; both copies read and write one state. |
| 10 | "Choose the app's colors tna type." | "Choose where Outpost gets its colors and fonts." | A typo in the generated image. |

## Improvements made on other tabs in this branch

| # | Tab | Change | Evidence |
| --- | --- | --- | --- |
| D1 | Post | **Sign out** moved from beside the publish button to About › Connection, as "Sign out of this device" with a line saying it removes drafts and the preferences copy. | 1.0.24 [Post](evidence/outpost-light-390-note.png) vs [Post](evidence-home/post-light-390.png) and [About](evidence-home/about-light-390.png) |
| D2 | All | The header's gear and its sheet are named **Preferences**, one name for one concept. | [Preferences sheet](evidence-home/preferences-sheet-light-390.png) |
| D3 | About | Four sections: Connection, Preferences, Help and documentation, Project. Adds Sharing and shortcuts, version and credits, and Reset preferences. | [About](evidence-home/about-light-390.png) |
| D4 | About, sheet | The three preference titles share one style, and inside About they sit below the "Preferences" heading instead of outranking it. | same |

## Proposed next improvements (not in this branch)

Ranked by how much of the screen or the task they affect. Each keeps native controls, every variant, and the token contract.

| # | Tab | Observation | Proposal |
| --- | --- | --- | --- |
| P1 | Doing | 15 type tiles in two columns fill about 560 px before the first field ([capture](evidence/outpost-light-390-listen.png)). | Show the type as a compact radio grid in three columns from 360 px, or the six most-used types plus a "More types" disclosure that keeps the selected one visible. The brief allows a compact picker when every option stays discoverable. |
| P2 | Reply | 11 type tiles take six rows ([capture](evidence/outpost-light-390-reply.png)). | Same treatment as P1. |
| P3 | Post | Five style tiles take three rows ([capture](evidence-home/post-light-390.png)); the mockup fits them in one row with icons. | One row of five icon-and-label radio tiles at 360 px and up, wrapping below that. |
| P4 | All composing tabs | With an empty form the publish button is a dashed outline that reads as missing, not as waiting for input. | A full-width primary button at the form's end, with the variant-specific label ("Post article" says more than the mockup's "Publish Post") and a one-line hint naming the missing required field. |
| P5 | Header | At 390 px the Light / Dark / System switch wraps under the wordmark and pushes every tab down about 50 px. | Keep wordmark, switch, and gear on one row from 375 px: signpost, wordmark, three 44 px segments, and a 44 px gear need about 330 px of the 343 px available. |
| P6 | Header | The selected appearance segment uses the same orange fill as the selected tab and the publish button, so three orange controls compete on one screen. | Use the chip-active tokens (violet tint) for the selected segment, as the mockup does, and keep orange for the publish action. |
| P7 | Photo | The photo picker is an unstyled native "Choose Files" control ([capture](evidence/outpost-light-390-photo.png)). | An "Add photos" button with a camera icon that opens the same native input, plus the selected count in text. |
| P8 | Preferences | Use Site Theme is a blind choice: you see the result only after picking it. | A labeled preview row under Use Site Theme: background, text, and accent swatches each with its name and the resolved value as text. |
| P9 | Doing | Rating is a free-text field labeled "1–5". | A five-option radio group with a "No rating" choice. |
| P10 | Post, Photo, Doing | Venue name and the OpenStreetMap disclosure sit in the main flow of every kind. | Group them in a "Location" row beside More options, as the mockup groups Categories and tags. |
| P11 | Post | Three lines of Markdown help under every body field. | One line ("Markdown and HTML pass through; your site renders them") with the detail in the docs. |
| P12 | Install | The manifest has no `shortcuts`, so a long-press on the Android icon offers nothing. | Manifest shortcuts for New post, New reply, and New photo, pointing at `/post/?mode=…`, which the share-target dispatcher already routes. |

P1 to P4 change the most screen space per tap. P5 and P6 touch every screen. P12 is the smallest change.
