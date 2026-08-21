---
title: Troubleshooting
description: "Fixes for common Outpost problems: missing-plugin notices, managed-hosting sign-in failures, posts that never appear, stuck offline queues, and 404s."
---

Symptoms, likely causes, and fixes for the problems Outpost users actually hit, based on the plugin's changelog and known issues.

## Outpost shows a notice about a missing plugin

**Symptom:** wp-admin shows "Outpost needs the IndieAuth plugin" (or Micropub), and `/post` shows a setup page instead of the composer.

**Cause:** Outpost's full feature surface only turns on when both IndieAuth and Micropub are installed and active. IndieAuth comes first in the chain — Micropub itself requires it.

**Fix:** follow the notice's install/activate link, or go to Plugins → Add New and install IndieAuth, then Micropub, from WordPress.org. Reload `/post` afterward.

**Check next:** if the notice says your WordPress or PHP version is too old, upgrade to WordPress 6.5+ / PHP 8.2+ — the version check runs before the dependency check.

## Sign-in or lookups fail on managed hosting (GoDaddy and similar)

**Symptom:** you can sign in, but posting returns "forbidden"/"unauthorized"; or the media lookup says "The lookup was rejected. Sign out and back in to refresh your token" even after doing exactly that.

**Cause:** some managed WordPress hosts (GoDaddy is the documented case) strip the `Authorization` HTTP header before it reaches PHP, so IndieAuth never sees your token. Hosts can also rewrite response status codes at their gateway, which used to make successful posts look like failures.

**Fix:** update Outpost. The plugin has shipped a series of workarounds (versions 0.1.105, 0.1.112, and 0.1.113 in the changelog): tokens now also travel in the request body where hosts can't strip them, and any 2xx response counts as success.

**Check next:** if you're on a managed host other than GoDaddy and still see auth failures on a current version, that host may need its own workaround — open an issue (below) with the host's name.

## The composer said it posted, but there's no post

**Symptom:** the composer reports success — especially the softer message "Posted, but the server did not return a link. Check your site to confirm it published" — but no post exists on your site.

**Cause:** root-caused (2026-07-09): the Micropub plugin, version 2.5.0 and earlier, answered failed post inserts with a success status — so when the server rejected an entry (seen with the Follow, Eat, Drink, and Weather variants), the composer had nothing but a success code and an absent link to go on.

**Fix:** update the Micropub plugin to 2.5.1 or newer. From that version the server returns a real error code, and the composer shows the actual failure instead of a hedged success. Outpost also shows an admin notice when the installed Micropub predates 2.5.1.

**Check next:** on managed hosts, a rewritten status code can produce the same "no link returned" message even though the post published — so always check the site before re-submitting, or you may create duplicates.

## Posts are stuck in the offline queue

**Symptom:** drafts sit in the queue with a growing attempt count instead of publishing.

**Cause:** the queue captures posts when the network or the Micropub endpoint is unreachable, and replays them automatically when the browser comes back online. Each retry failure records the reason. One special case: signing out doesn't clear the queue, so entries queued under an old session will keep failing with an authorization (401) error.

**Fix:** check the queue entry's error. For auth errors, dismiss the stale entries and re-post while signed in. For network errors, retry once you have a stable connection.

**Check next:** photo posts replay without re-uploading (the media uploaded before the post queued) — a stuck photo post is about the post, not the image.

## /post returns a 404

**Symptom:** visiting `/post/` gives your theme's 404 page.

**Cause:** the rewrite rules that serve the composer aren't registered — usually right after a manual install or a migration.

**Fix:** deactivate and reactivate Outpost (activation registers the rules), or re-save permalinks at Settings → Permalinks.

## Sharing to Outpost from a phone shows a plain error page

**Symptom:** sharing a page to Outpost from your phone's share sheet lands on a bare text page reading "Outpost share-target requires an authenticated user" instead of the composer.

**Cause:** older Outpost versions required a WordPress login cookie on the share-target route. The installed app signs you in with IndieAuth tokens stored on the device, not a WordPress cookie, so shares from a phone could never pass that check — sharing only worked on a desktop browser that also happened to be logged in to wp-admin.

**Fix:** update Outpost. Share dispatch no longer requires the cookie; the composer applies your normal sign-in after the share opens it. If you aren't signed in, the share data waits while you sign in and pre-fills afterward.

## Outpost doesn't appear in the Android share sheet

**Symptom:** the app is installed on Android, but sharing a page from another app never offers Outpost as a destination.

**Cause:** Android only registers a web app as a share target when it installs as a full app (a WebAPK), and that install path needs the fixed-size PNG icons older Outpost versions didn't ship in the manifest — Android quietly fell back to a plain home-screen shortcut, which never receives shares.

**Fix:** update Outpost, then remove the installed app and add it to your home screen again from Chrome so Android reinstalls it with share-target registration. On iOS, the share sheet works through the Outpost Shortcut instead (Settings → Outpost iOS Shortcut) — Safari has no share-target support for web apps.

## Stale composer after an update

**Symptom:** after updating the plugin, the composer looks or behaves like the old version.

**Cause:** aggressive page/CDN caching on the composer HTML. The plugin's own test docs note real-world caching pain on managed hosts with CDN layers.

**Fix:** clear your host/CDN cache and hard-reload the composer. As a PWA it also updates its service worker on reload.

## When to open an issue

If none of the above fits — or a documented fix doesn't work on a current version — open an issue at [github.com/courtneyr-dev/outpost/issues](https://github.com/courtneyr-dev/outpost/issues). Include your WordPress and PHP versions, your host, the composer mode you used, and the exact message you saw. For security problems, follow [SECURITY.md](https://github.com/courtneyr-dev/outpost/blob/main/docs/../SECURITY.md) instead of filing a public issue.
