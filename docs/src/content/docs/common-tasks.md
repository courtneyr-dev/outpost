---
title: Common tasks
description: "How-to recipes for everyday Outpost work: notes, replies, photos, bookmarklets, offline posting, life-tracking services, and syndication checks."
---

Step-by-step recipes for the things you'll do most often with Outpost. All of these assume the setup in [Getting started](/outpost/getting-started/) is done.

## Post a note

1. Open the composer at `/post/` (or tap your home-screen icon).
2. Choose the Note variant if the Post tab opened elsewhere.
3. Write your text — Note mode also supports voice input.
4. Review the syndication chips: configured destinations are on by default; tap to turn any off.
5. Tap post and follow the link in the success message to your new post.

## Reply to (or like, repost, bookmark) a page

1. In the composer, switch to the Reply group and pick the response type: Reply, Like, Repost, or Bookmark.
2. Paste the URL you're responding to. The composer shows the target's context.
3. Add your commentary (Reply and Bookmark), then post. The published post links back to the source with the right microformats (in-reply-to, like-of, repost-of).

Faster paths so you don't paste URLs by hand:

- **From your phone's share sheet:** installed as a PWA on Android, Outpost appears as a share target — share any page from any app straight into the composer. On iOS, use the Shortcut bridge (Settings → Outpost iOS Shortcut).
- **From a desktop browser:** use bookmarklets (next task).

## Add the bookmarklets to your browser

1. In wp-admin, open Outpost (the first Outpost menu).
2. Find the bookmarklet section — one bookmarklet per response type (Reply, Like, Repost, Bookmark), each pre-built with your site's URL.
3. Drag each one to your browser's bookmarks bar.
4. On any web page, click a bookmarklet to open the Outpost composer with that page's URL, title, and your selected text pre-filled.

## Post a photo

1. In the composer, switch to Photo mode.
2. Upload from your camera roll.
3. Enter alt text — it's required; the post won't submit without a description of the image.
4. Add any caption text and post.

## Log something you're listening to, watching, or reading

Works best with the Post Kinds for IndieWeb companion plugin: the "Look it up" search requires it, and without it these entries publish as generic notes instead of proper post kinds.

1. Switch to the Listen, Watch, Read, Checkin, or Play mode.
2. Use the "Look it up" search to find the album, film, book, venue, or game — it fills the title, creator, and cover art from Post Kinds' lookup services (MusicBrainz, TMDB, Open Library, Foursquare/Nominatim, BoardGameGeek/RAWG). Watch mode has a Movie/TV toggle.
3. Add your note or rating and post.

If lookup says a source is "not configured," add the relevant API key under Post Kinds → API Connections — music, book, game, and venue search only work once their keys are set there.

## Write a long-form article

Choose the Article variant in the composer. Outpost hands off to the block editor (`/wp-admin/post-new.php`) — the composer is for fast posts; long-form stays in the editor.

## Change which variant the composer opens to

1. Go to wp-admin → Outpost (first menu) and scroll to the Settings section.
2. Set "Default Post variant" (Article, Note, Status, Aside, or Quote) and save. See [Settings](/outpost/settings/).

## Confirm a post syndicated

- On the post itself, syndicated copies appear as links appended to the content ("Also on …" style `u-syndication` links).
- In wp-admin → Posts, Outpost adds a syndication status column to the post list.

## Post while offline

Just post — if the network is down, the draft queues on your device and Outpost submits it automatically when the connection returns. The queue shows pending entries with retry and dismiss controls. Note that signing out doesn't clear the queue; queued entries from a stale session will fail with an authorization error you can dismiss.

## Connect a life-tracking service

1. Go to wp-admin → Outpost (first menu) → OAuth Connections.
2. Click Connect next to the provider (Oura, WHOOP, Polar, Notion, Ride With GPS, Ravelry — as available) and approve access on the provider's site.
3. Back in WordPress, the provider shows as connected. In the block editor, the Outpost sidebar offers a "fetch recent" picker to pull recent items from connected providers into a post.

## Troubleshoot a post that didn't appear

See [Troubleshooting](/outpost/troubleshooting/) — start with "The composer said it posted, but there's no post."
