---
title: How Outpost shapes a post
description: "What Outpost writes to your site, how it picks a post format, the IndieWeb specs each post is built on, and what XFN adds — the background behind the composer's choices."
---

The composer's tabs map onto a small set of rules. This page explains them so what lands on your site isn't a surprise. It's background, not setup — for step-by-step tasks see [Common tasks](/outpost/common-tasks/).

## What Outpost posts

Outpost writes **posts** — the standard WordPress `post` type. It does not write:

- Pages.
- Custom post types, unless the Micropub plugin on your site is configured to route there.
- Media outside the photo upload flow.
- Comments. A Reply is an h-entry on your own site that links to the page you're replying to, not a comment left on that page.

Custom post types: Outpost speaks Micropub, and the [Micropub plugin](https://github.com/dshanske/wordpress-micropub#filters) decides which post type a request lands on. By default that's `post`. Routing to a custom type is a server-side filter on that plugin — Outpost doesn't configure it; it talks to whatever the server accepts.

## Post formats

[Post formats](https://wordpress.org/documentation/article/post-formats/) are WordPress's classification of a post as Status, Aside, Image, Gallery, Video, Audio, Quote, Link, or Standard. With the [Post Formats for Block Themes](https://wordpress.org/plugins/post-formats-for-block-themes/) companion active and Auto Post-Format inference enabled in the composer defaults ([Settings](/outpost/settings/)), Outpost sets the format from what the post contains:

| What the post carries | Format |
|---|---|
| like-of, repost-of, or bookmark-of | Link |
| One photo | Image |
| More than one photo | Gallery |
| listen-of | Audio |
| watch-of | Video |
| in-reply-to | Status |
| A note of 280 characters or fewer | Status |
| A longer note | Standard |
| An article (a titled post) | Standard |
| The Quote style | Quote |

Block themes don't opt into post formats on their own, which is why the companion gates both the format selector and this inference. Theme developers: the [post formats reference](https://developer.wordpress.org/themes/functionality/post-formats/) covers `add_theme_support()`.

## The IndieWeb specs behind every post

Every post Outpost sends is built on these. Primers, if you want to see what a post looks like on the wire:

- **[Micropub](https://micropub.spec.indieweb.org/)** — the posting protocol. The composer POSTs a form-encoded body with `h=entry` and the post's properties (content, name, photo, in-reply-to, and so on) to your site's Micropub endpoint, and gets back a Location header pointing at the new post.
- **[IndieAuth](https://indieauth.spec.indieweb.org/)** — the sign-in. The composer discovers your site's authorization endpoint, sends you there to consent, exchanges the returned code for a bearer token, and stores that token encrypted in your browser.
- **[h-entry](https://microformats.org/wiki/h-entry)** — the shape of a post: properties such as `content`, `name` (the title), `photo`, `in-reply-to`, `like-of`, and `category`. Every composer variant is a different combination of h-entry properties.
- **[h-card](https://microformats.org/wiki/h-card)** — the marker for a person or organization on a page. Outpost reads h-cards from Reply targets to show the page's author in the reply context.
- **[h-cite](https://microformats.org/wiki/h-cite)** — a citation. The Quote style's source maps here.
- **[Microformats2](https://microformats.org/wiki/microformats2)** — the umbrella all the h-* shapes live under.
- **[Webmention](https://indieweb.org/Webmention)** — how replies, likes, and reposts on syndicated copies find their way back to your site. Outpost doesn't implement it; your site does, through the [Webmention plugin](https://wordpress.org/plugins/webmention/).

## XFN relationships

The relationship picker on Reply and Doing target URLs is [XFN](https://gmpg.org/xfn/) (XHTML Friends Network): a way to say how you know the person at the other end of a link — `friend`, `met`, `colleague`, `contact`, `spouse`, and the rest of the [XFN 1.1 vocabulary](https://gmpg.org/xfn/11). Outpost stores your choice with the post and applies it to the link.

The picker appears only when the [Link Extension for XFN](https://wordpress.org/plugins/link-extension-for-xfn/) companion is active. Without it your theme isn't reading the relationship values, so Outpost hides the picker rather than suggest it does something it doesn't.
