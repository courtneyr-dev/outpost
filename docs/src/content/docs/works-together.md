---
title: Works together
description: "What Outpost looks like when Post Kinds for IndieWeb, Post Formats for Block Themes, and Link Extension for XFN are active on the same styled site."
---

Outpost is built to sit at the center of a small suite of IndieWeb plugins: [Post Kinds for IndieWeb in Block Themes](https://courtneyr-dev.github.io/post-kinds-for-indieweb/), [Post Formats for Block Themes](https://courtneyr-dev.github.io/post-formats-for-block-themes/), and [Link Extension for XFN](https://courtneyr-dev.github.io/link-extension-for-xfn/). Outpost detects companions at runtime — activate one and the composer grows the matching capability, deactivate it and the composer quietly narrows. None of them is required.

The screenshots on this page come from a demo site running the whole suite with a styled block theme (plus the IndieAuth, Micropub, and Webmention building blocks), so they show a real site's look rather than a default install.

## The full stack on one site

![WordPress plugins list showing IndieAuth, Link Extension for XFN, Micropub, Outpost, Post Formats for Block Themes, Post Kinds for IndieWeb, and Webmention all active](../../assets/screenshots/suite-plugins-active.png)

The suite plus its building blocks: IndieAuth signs you in, Micropub receives what the composer publishes, Webmention handles responses, and the three companion plugins shape how posts are written and displayed.

## Compose on your phone

![Outpost composer on a phone, signed in over IndieAuth, showing post style choices](../../assets/screenshots/suite-composer-note.png)

The composer signed in against the demo site over IndieAuth. The tab row is always the full set; with Post Kinds active, every entry — from a listen log to a craft, event, or review — lands classified as its proper kind, and with Post Formats active, the style you pick flows into format inference once the post lands.

## What arrives on your site

![Listen Card for an album rendered on a zine-styled block theme with cover art, star rating, and hard-shadow card styling](../../assets/screenshots/suite-frontend-listen-card.png)

A listen logged from the composer renders as a Post Kinds Listen Card in the theme's own styling — cover art, rating, and microformats included. With Webmention active the post also grows a likes-and-reposts section and a reply form.

## Formats and kinds stay in step

![Choose Post Format modal where each format card shows a Sets Post Kind line, with the Post Kind panel visible in the editor sidebar](../../assets/screenshots/suite-editor-new-post.png)

Editing on the desktop side, Post Formats' selection modal shows the kind mapping Post Kinds registers — **Standard → Article**, **Aside → Note**, **Audio → Listen**. Posts published from Outpost pass through the same mapping, so a note from your phone and a note from the editor end up shaped identically.

## What each plugin adds

- **Outpost** — this plugin: the phone-friendly composer, syndication chips, offline queue, and share-sheet capture.
- **Post Kinds for IndieWeb** — card blocks and microformats for the kind-shaped posts the composer creates.
- **Post Formats for Block Themes** — format patterns, badges, and templates; formats map onto kinds automatically.
- **Link Extension for XFN** — relationship attributes on links in any post, including reply targets.
- **RSS Chat Routing** — [rss.chat](https://rss.chat) is a chat network built on RSS: your site publishes a post to it, people reply there, and the replies come back to your post as comments. Matthias Pfefferle's [RSS Chat plugin](https://github.com/pfefferle/wordpress-rss-chat) does the sending; on its own it sends only posts carrying the core *chat* post format. [RSS Chat Routing](https://github.com/courtneyr-dev/rss-chat-routing) chooses which posts go instead — by default post format, default Post Kind, or per post — and brings replies home as verified Webmentions. With it active, the composer's More panel adds the same per-post choice, **Send to rss.chat** (Site default, Include in RSS Chat, or Exclude from RSS Chat), sent as `mp-rss-chat-routing`, so you can opt a post in or out from the app without opening the editor.
- **ATmosphere** — [ATmosphere](https://wordpress.org/plugins/atmosphere/) publishes each post to the AT Protocol: a Bluesky post or thread linking back to your site, plus the full article stored on your AT Protocol account as a standard.site document (the `site.standard.*` lexicons). Bluesky replies, likes, and reposts come back as comments. Outpost adds no chip for it — posts from the composer cross-post like any other published post. Opt a post out from the block editor sidebar, and leave the **Bluesky via Bridgy** chip off when ATmosphere is connected so the same post doesn't reach Bluesky twice.
