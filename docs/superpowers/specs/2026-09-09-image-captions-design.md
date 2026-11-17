# Image captions, distinct from titles

**Date:** 2026-09-09
**Target:** Outpost 1.0.13, then a follow-up PKIW release
**Status:** approved design, not yet implemented

## Problem

Outpost's photo mode offers a title, a body, and per-photo alt text. There is
no way to write a line of prose that belongs to a photo rather than to the
post. Captions are currently written into the title instead, which is why
recent posts carry titles like "Anzuelo" and "Chloe goes for a ride" — the
title field is doing caption duty.

That breaks down for galleries. A post with six photos gets one title covering
all six, and `/stream` shows a single featured image with no sign the other
five exist. A one-photo post and a six-photo post look identical in the feed.

## Decisions

1. **Captions are per-image, not per-post.** Each photo carries its own
   caption. There is no post-level caption field.
2. **Titles stay.** Photo posts keep writing titles as they do today. Captions
   are additional, never a replacement, and no existing post changes.
3. **Captions appear on `/stream`,** not only on the single post view.
4. **A multi-image post shows a hero image plus a row of thumbnails** with a
   `+N` control past four, rather than one image or a full grid.
5. **The visible caption belongs to the hero image, and swaps** when a
   thumbnail is activated.

## Data model

The single source of truth is the attachment's caption — `post_excerpt` on the
attachment post. That is the field the media library shows, the block editor
edits, and core's Image block renders. Everything else reads from it.

Alt text and caption stay separate and serve different jobs. Alt describes the
image for someone who cannot see it; caption is prose everyone reads. Caption
is never prefilled from alt.

### Wire format

The caption rides the existing Micropub photo object as a sibling of `alt`:

```json
"photo": [
  { "value": "https://example.test/turkey-1.jpg",
    "alt": "Three wild turkeys on a suburban lawn",
    "caption": "They own this street now" }
]
```

Neither `alt` nor `caption` is in the Micropub specification. `alt` is
established convention and Outpost's bridge already reads it; `caption`
follows the same path. A Micropub server that does not recognize the key
ignores it and the post still succeeds.

### Storage path

```
composer  →  photo[].caption  →  after_micropub bridge  →  attachment post_excerpt
```

The bridge mirrors `apply_photo_alt_text()`: the same pair-by-URL logic, the
same `edit_post` capability gate, writing one field over.

## Outpost changes

### Composer

`PhotoEntry` gains `caption: string`. Each photo card gains a caption input
below its existing alt input.

- Caption is optional. No new blocking validation; the existing
  "every photo needs alt text or mark it decorative" gate is untouched.
- Caption is empty by default and never prefilled from alt.
- Placeholder text carries the explanation: shown under the photo, leave empty
  for none.
- Decorative photos still get a caption field. `decorative` remains wired to
  alt only — a decorative image can still have a caption.
- The offline queue serializes `PhotoEntry`, so `caption` rides along as a new
  optional string. Drafts queued before this change deserialize with an empty
  caption.

### Bridge

A new `apply_photo_captions()` alongside `apply_photo_alt_text()` in
`class-micropub-bridges.php`, called from `apply_bridges()` and therefore
already behind the post-level `edit_post` check.

It pairs each `photo[]` entry to its attachment by URL, deduplicating by URL
the way the alt bridge does, and writes a non-empty caption to the
attachment's `post_excerpt` via `wp_update_post()`. Empty and absent captions
write nothing.

## PKIW changes

`/stream` cards are built by `functions-stream-card.php` in
`post-kinds-for-indieweb-in-block-themes` — 448 lines, currently rendering
`post_thumbnail` only, with no `get_attached_media` call and no gallery
concept. This is the larger half of the work and ships separately.

### Naming

`pk-caption` is already taken: it wraps the card's title and date. The image
caption uses `pk-media__caption`, nested under the existing `pk-media` block.

### Rendering

Server-rendered first, enhanced after. The card emits, in PHP:

- the featured image as the hero, as today
- its caption in `pk-media__caption`
- a row of up to four thumbnails, each a `<button>` inside a labeled group,
  each with an accessible name from that image's alt text
- past four images, a `+N` control linking to the post

All of that is correct with JavaScript disabled or still loading, which is what
feed readers and unfurlers parsing the `h-entry` will see. The swap is
enhancement layered on top and never the reason a caption exists.

### Interaction

Activating a thumbnail swaps the hero image and the visible caption together.

- Thumbnails are reachable by Tab and activate on Enter and Space. Hover is an
  addition, not the mechanism — the primary device here is a phone.
- `pk-media__caption` carries `aria-live="polite"` so the new caption is
  announced. Polite, not assertive: this must not interrupt.
- The hero image's `alt` updates with the swap, so the announced caption and
  the visible image never disagree.
- Any crossfade sits behind `prefers-reduced-motion: reduce` and defaults to an
  instant swap.

### Deliberately excluded

No lightbox — that belongs to the post view, not the feed. No autoplay or
carousel rotation — motion in a feed being scrolled is hostile.

## Testing

**Outpost, PHP.** Caption pairs to the correct attachment by URL and writes to
`post_excerpt`. A post with no captions writes nothing. The cross-user case
from the existing bridge tests applies unchanged, since the new writer sits
behind the same `edit_post` gate.

**Outpost, PWA.** `PhotoEntry.caption` round-trips through the offline queue. A
draft serialized before this change deserializes with an empty caption and no
error. An explicit test asserts caption is never prefilled from alt — that is
the most likely regression for someone to introduce later as a convenience.

**PKIW.** The card renders caption and thumbnail row server-side with no
JavaScript. Then the interactive layer: Tab reaches thumbnails, Enter and Space
activate, the live region updates, and the hero's `alt` changes with it.

## Rollout

**Outpost 1.0.13 first.** Captions are authored and stored on attachments and
nothing visibly changes: `/stream` ignores them until PKIW ships. Worst case is
captions nobody sees yet, which makes this a safe and reversible first release.
It is also a small, self-contained change to a plugin currently under
WordPress.org review.

**PKIW second,** once real caption data exists on the site to render against.
Building the card first would mean styling against fixtures and guessing how
the captions read at their real lengths.

## Open item

Something server-side turns Outpost's `photo[]` array into
`wp:group.h-entry` + `wp:gallery` block markup. It is not Outpost, which
registers only `after_micropub` and builds no post content; not the Micropub
plugin; and not Post Formats for Block Themes, whose `gallery.php` is a
27-line block pattern. Until that generator is identified, it is unknown where
a `<figcaption>` would be injected for the single-post view.

This does not block the work. The attachment is the source of truth either way,
and the `/stream` card reads the attachment directly. It decides only whether
single-post captions render for free through core's Image block or need a small
filter. Resolve it during implementation of the Outpost half.
