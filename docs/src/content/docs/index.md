---
title: Outpost
description: "User documentation for Outpost, a mobile-first PWA composer for WordPress: installation, setup, everyday posting tasks, and troubleshooting."
---

Outpost is a mobile-first Progressive Web App (PWA) composer for WordPress. These docs help you install the plugin, set it up, and post from your phone.

## What Outpost does

Outpost serves a posting app at `/post` on your own WordPress site. Open it on your phone, write a note, reply to a URL, or log something you're listening to or watching, and the post publishes to your site through the Micropub API. Syndication chips let you send the same post to other platforms — the [IndieWeb](https://indieweb.org/) pattern called [POSSE](https://indieweb.org/POSSE) (Publish on your Own Site, Syndicate Elsewhere).

Because the composer is a real PWA, it installs to your iOS or Android home screen, works offline with a queued-draft system, and hooks into the OS share sheet so you can reply to any page from any app.

## Who it's for

Outpost is for WordPress site owners who want fast mobile posting without Jetpack, app stores, or third-party accounts. It authenticates with [IndieAuth](https://indieauth.net/) — a sign-in protocol where your own website is your identity.

## Before you install

- WordPress 6.5 or newer, PHP 8.2 or newer
- The [IndieAuth plugin](https://wordpress.org/plugins/indieauth/) (required)
- The [Micropub plugin](https://wordpress.org/plugins/micropub/) (required)

If either required plugin is missing or inactive, Outpost shows an admin notice with an install link, and the `/post` page shows a setup prompt instead of the composer.

Companion plugins are detected the moment you activate them. Recommended: Post Kinds for IndieWeb in Block Themes (the media "Look it up" search, plus kind classification and card display for every entry the composer posts — it covers all 36 of its kinds), Webmention (so the replies and likes you send are received, and theirs show on your posts), and Syndication Links (your destinations become the composer's chips). Also detected when present: Post Formats for Block Themes, Link Extension for XFN, ActivityPub, Bridgy, Yoast SEO, and Accessibility Checker.

## Is Outpost on WordPress.org?

Not yet. Outpost is not available in the WordPress.org plugin directory — you install it from a release ZIP on GitHub. [Installation](/outpost/installation/) covers the whole process, and [Playground preview](/outpost/playground/) lets you try it in your browser first without installing anything.

## Get started

1. [Installation](/outpost/installation/) — install from GitHub and activate the dependencies.
2. [Getting started](/outpost/getting-started/) — sign in, post your first note, install to your home screen.
3. [Settings](/outpost/settings/) — tune composer defaults, API keys, and connections.

## Get help

- [Troubleshooting](/outpost/troubleshooting/) — symptoms, causes, and fixes.
- [FAQ](/outpost/faq/) — quick answers to common questions.
- [Report an issue](https://github.com/courtneyr-dev/outpost/issues) on GitHub.

## Source code

Outpost is developed in the open at [github.com/courtneyr-dev/outpost](https://github.com/courtneyr-dev/outpost). Contributor and developer documentation lives in the repository, separate from these user docs.
