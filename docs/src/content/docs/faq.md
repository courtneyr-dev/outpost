---
title: FAQ
description: "Short answers to common Outpost questions: WordPress.org availability, Micropub and IndieAuth, POSSE, offline use, and what happens on deactivation."
---

Quick answers to the questions Outpost users ask most.

## Is Outpost on WordPress.org?

Not yet. Outpost (version 1.0.24) isn't listed in the WordPress.org plugin directory. Install it from GitHub with the [installation guide](/outpost/installation/), and update it with [Update Outpost](/outpost/update/): WordPress won't show update notices for it. Its two required plugins, IndieAuth and Micropub, are on WordPress.org.

## Does Outpost require Jetpack?

No. That's much of the point: Outpost uses Micropub for publishing and IndieAuth for sign-in, so mobile posting works on a self-hosted site with no Jetpack, no app store, and no third-party account.

## What are Micropub and IndieAuth?

[Micropub](https://indieweb.org/Micropub) is an open API standard for publishing to your own website — the Micropub plugin gives your WordPress site that endpoint, and the Outpost composer posts through it. [IndieAuth](https://indieauth.net/) is a sign-in protocol where your own website is your identity; the IndieAuth plugin lets you sign in to the composer with your site. Both plugins are required.

## What is POSSE?

[Publish on your Own Site, Syndicate Elsewhere](https://indieweb.org/POSSE) — an [IndieWeb](https://indieweb.org/) practice where the canonical copy of a post lives on your site, and copies go out to other platforms. Outpost's syndication chips implement this: every configured destination is enabled by default on each post, and you tap a chip to skip it.

## Does Outpost work offline?

Yes, for composing. A post you make offline waits in a queue on your device and sends when the connection returns, with retry and dismiss controls in the composer. The queue is stored in your browser's IndexedDB without encryption; your sign-in token is stored separately and is encrypted. [Privacy and data](/outpost/privacy-and-data/#data-stored-on-your-device-browser) lists what stays on the device.

## Do I lose what I typed if I reload?

No. Each tab saves its unsent text to your device as you type, and it comes back after a reload or a fresh sign-in as the same site. Photos themselves aren't kept, but their alt text and captions are. See [Get your draft back after a reload](/outpost/common-tasks/#get-your-draft-back-after-a-reload).

## Does it work on iPhone?

Yes. The composer installs to the iOS home screen via Safari's "Add to Home Screen." iOS Safari doesn't support the share-sheet Web Share Target API, so sharing pages into Outpost from other apps goes through an Apple Shortcut you build in the Shortcuts app. [Share to Outpost from your phone](/outpost/common-tasks/#iphone-and-ipad) has the six steps. The ready-made Shortcut on wp-admin's Settings → Outpost iOS Shortcut page isn't published yet in 1.0.24.

## Does it work on Android?

Yes. In Chrome, accept the install banner, or open the browser menu and choose **Install app** (or **Add to Home screen** on older versions). Once installed as a PWA, Outpost also registers as a share target — share any page from any app straight into the composer, no extra setup. That's one step simpler than iOS, which needs the Shortcut bridge for sharing.

## Do I need Post Kinds for the Doing and Life modes?

The modes themselves are built in and always visible — the composer covers every kind Post Kinds registers, from Listen and Watch through Craft, Event, Review, Sleep, and Question. But with the Post Kinds for IndieWeb in Block Themes companion plugin active, each entry is classified and rendered as its proper post kind on your site (without it they publish as generic notes) and the media "Look it up" search works — Outpost detects companions at runtime, no reconfiguration needed. When the companion is active, the composer also names the kind on each post explicitly, which is how look-alike shapes (an issue and a reply share the same properties) land as the right kind. The lookup also needs API keys configured under Post Kinds → API Connections.

## Can I write long posts in Outpost?

Yes, with limits. The **Article** style on the Post tab takes a title and body text and publishes a titled post. For long pieces with images, headings, and layout, use the block editor; posts from Outpost open there for editing.

## Can I change the /post URL?

No. The composer is always at `/post` on your site, and no setting changes it.

## Which platforms can I syndicate to?

Destinations depend on what you configure: Bridgy-backed networks (Mastodon, Bluesky, Flickr, GitHub, Reddit; Bridgy no longer supports X, Facebook, or Instagram), the fediverse through the ActivityPub plugin, Telegraph, and manual-share handoffs to apps such as Facebook, X, Threads, and Instagram, where you finish the post yourself. Outpost also has Beehiiv, Buttondown, Kit, and write.as destinations, but 1.0.24 has no on-screen way to choose them for a post. [Supported services](/outpost/supported-services/) has the full list and setup.

## What happens to my posts if I deactivate Outpost?

Nothing — posts are regular WordPress posts created through Micropub. Deactivating removes the composer at `/post` but touches no content. See [Privacy and data](/outpost/privacy-and-data/) for what uninstalling cleans up.
