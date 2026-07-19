---
title: FAQ
description: "Short answers to common Outpost questions: WordPress.org availability, Micropub and IndieAuth, POSSE, offline use, and what happens on deactivation."
---

Quick answers to the questions Outpost users ask most.

## Is Outpost on WordPress.org?

Not that we can confirm — Outpost (version 1.0.0) is not yet confirmed to be listed there. Install it from GitHub per the [installation guide](/outpost/installation/). Its two required dependencies (IndieAuth and Micropub) are on WordPress.org.

## Does Outpost require Jetpack?

No. That's much of the point: Outpost uses Micropub for publishing and IndieAuth for sign-in, so mobile posting works on a self-hosted site with no Jetpack, no app store, and no third-party account.

## What are Micropub and IndieAuth?

[Micropub](https://indieweb.org/Micropub) is an open API standard for publishing to your own website — the Micropub plugin gives your WordPress site that endpoint, and the Outpost composer posts through it. [IndieAuth](https://indieauth.net/) is a sign-in protocol where your own website is your identity; the IndieAuth plugin lets you sign in to the composer with your site. Both plugins are required.

## What is POSSE?

[Publish on your Own Site, Syndicate Elsewhere](https://indieweb.org/POSSE) — an [IndieWeb](https://indieweb.org/) practice where the canonical copy of a post lives on your site, and copies go out to other platforms. Outpost's syndication chips implement this: every configured destination is enabled by default on each post, and you tap a chip to skip it.

## Does Outpost work offline?

Yes, for composing. Drafts written offline queue on your device and submit automatically when the connection returns, with retry and dismiss controls in the composer. The plugin's readme describes the queue storage as encrypted; we haven't verified that in the current code, so treat it as the maintainer's statement.

## Does it work on iPhone?

Yes. The composer installs to the iOS home screen via Safari's "Add to Home Screen." iOS Safari doesn't support the share-sheet Web Share Target API, so sharing pages into Outpost from other apps goes through an Apple Shortcut instead — set it up at Settings → Outpost iOS Shortcut in wp-admin.

## Does it work on Android?

Yes. In Chrome, accept the install banner, or open the browser menu and choose **Install app** (or **Add to Home screen** on older versions). Once installed as a PWA, Outpost also registers as a share target — share any page from any app straight into the composer, no extra setup. That's one step simpler than iOS, which needs the Shortcut bridge for sharing.

## Do I need Post Kinds for the Listen, Watch, Read, Checkin, and Play modes?

The modes themselves are built in and always visible. But with the Post Kinds for IndieWeb in Block Themes companion plugin active, those entries render as proper post kinds on your site (without it they publish as generic notes) and the media "Look it up" search works — Outpost detects companions at runtime, no reconfiguration needed. The lookup also needs API keys configured under Post Kinds → API Connections.

## Can I write long posts in Outpost?

Not in the composer itself. The Article variant hands off to the block editor at `/wp-admin/post-new.php` — Outpost is for fast, phone-sized posts.

## Can I change the /post URL?

Not currently. The readme mentions a configurable route slug, but the current code serves the composer at the fixed `/post` path with no setting for it.

## Which platforms can I syndicate to?

Destinations depend on what you configure: Bridgy-backed silos (Mastodon, Bluesky, Flickr, GitHub, Reddit — Bridgy no longer supports Twitter/X, Facebook, or Instagram), ActivityPub via its plugin, newsletter services and other platforms via adapters with API keys, and manual-share platforms where Outpost reminds you to paste the copy's URL after you post it yourself.

## What happens to my posts if I deactivate Outpost?

Nothing — posts are regular WordPress posts created through Micropub. Deactivating removes the composer at `/post` but touches no content. See [Privacy and data](/outpost/privacy-and-data/) for what uninstalling cleans up.
