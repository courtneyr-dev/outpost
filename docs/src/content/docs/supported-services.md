---
title: Supported services
description: "Every service Outpost works with in version 1.0.24: URL capture sources, connected accounts, POSSE destinations, Bridgy, and manual-share handoffs."
---

Outpost works with outside services in four ways: it **captures** details when you paste a link, **pulls recent activity** from accounts you connect, **sends a copy** of your post to publishing platforms, and **hands off** a post to apps it can't post to directly. This page lists every service in version 1.0.24. Links in the first column go to the developer note for that service in the GitHub repository.

For what each service is sent and stores, read [Privacy and data](/outpost/privacy-and-data/) before you connect anything.

## Capture details from a pasted link

When you paste a link into the composer, Outpost recognizes the host and fills in what it can: title, author, image, and a suggested post type. These hosts have a dedicated capture source:

| Service | What pasting a link does |
| --- | --- |
| [Bear Blog](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/bear-blog.md) | Suggests a Read post for the article |
| [Mataroa](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/mataroa.md) | Suggests a Read post for the article |
| [iFixit](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/ifixit.md) | Suggests a Bookmark for the repair guide. iFixit content is licensed CC BY-NC-SA; Outpost doesn't add the attribution line for you, so credit iFixit in your post. |
| [Snipd](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/snipd.md) | Captures the podcast snip from a Snipd share link |
| [Pretalx](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/pretalx.md) | Captures the talk details from a Pretalx page |
| [Notion](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/notion.md) | Reads a shared Notion page you cite. Needs a connected Notion account. |

Nineteen more hosts also have their own capture source: Spotify, YouTube, Twitch, Goodreads, Last.fm, Readwise, Amazon, Pinterest, TikTok, Vimeo, SoundCloud, Apple Music, Apple Podcasts, Bandcamp, Substack, Medium, Reddit, Mastodon, and Bluesky. Any other link falls back to the page's own metadata (Open Graph tags and structured data).

## Pull recent activity from a connected account

Connect an account at wp-admin → **Outpost** → **OAuth Connections**. Then, in the **block editor**, open the Outpost sidebar and choose **Add from connected platforms** to pull a recent item into the post you're writing. The phone composer doesn't have this picker.

| Service | What the picker lists |
| --- | --- |
| [Oura](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/oura.md) | Recent workouts and sleep sessions |
| [WHOOP](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/whoop.md) | Recent cycles and recoveries |
| [Polar Flow](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/polar.md) | Recent exercise sessions |

The picker also lists a **Test (sample data)** provider on every site. It returns made-up items so you can see how the picker works; it contacts no service.

Connected-account tokens don't refresh on their own in 1.0.24. If the picker stops listing items from a service you connected, disconnect and reconnect it on the OAuth Connections page.

**Connect now, used later.** The OAuth Connections page also connects **Ravelry** and **Ride With GPS**. In 1.0.24 no composer or editor feature reads from these two accounts yet; connecting one only stores the token and confirms it works.

## Send a copy of your post to a publishing platform (POSSE)

These destinations receive a copy of a WordPress post when it publishes:

| Destination | What gets published | Setup |
| --- | --- | --- |
| [Beehiiv](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/beehiiv.md) | A post in your Beehiiv publication | API key under Outpost → Settings → API Keys |
| [Buttondown](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/buttondown.md) | A Buttondown email | API key under Outpost → Settings → API Keys |
| [Kit](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/kit.md) | A Kit (formerly ConvertKit) broadcast | API key under Outpost → Settings → API Keys |
| [write.as](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/write-as.md) | A write.as Markdown post | API key under Outpost → Settings → API Keys |
| [Telegraph](https://github.com/courtneyr-dev/outpost/blob/main/docs/adapters/telegraph.md) | A public Telegraph page | No key. Turn it on with WP-CLI: `wp option update outpost_telegraph_enabled 1`. It has no setting screen. |

Two limits to know before you set these up:

- **Saving an API key needs an encryption key first.** Define `OUTPOST_ENCRYPTION_KEY` in `wp-config.php` before you open the API Keys tab; without it the tab shows an error instead of the form. [Settings](/outpost/settings/#api-keys-tab) explains the key.
- **Beehiiv, Buttondown, Kit, and write.as have no on-screen selector yet.** Neither the composer nor the block editor lets you choose them for a post in 1.0.24. A developer can select them through the post's `_outpost_posse_targets` meta in the REST API; the developer notes linked above show how. Telegraph doesn't need selecting: once it's on, every newly published post goes to it.

A failed send is recorded on the post, in its `_outpost_posse_failures` meta, and retried. No admin notice reports it.

## Send to social networks through Bridgy

[Bridgy Publish](https://brid.gy/) posts your site's posts to Mastodon, Bluesky, GitHub, Flickr, and Reddit. Its endpoints (brid.gy, bsky.brid.gy, and fed.brid.gy) appear in the composer as syndication chips, on by default. Bridgy needs the [Webmention plugin](https://wordpress.org/plugins/webmention/) on your site. Bridgy no longer supports X, Facebook, or Instagram.

With the [ActivityPub plugin](https://wordpress.org/plugins/activitypub/) active, the fediverse also appears as a chip, and your posts federate from your own site without Bridgy.

## Hand off to apps Outpost can't post to

Some platforms have no posting API Outpost can use. For these, a chip opens the platform's own share page or app with your post's link and text filled in, and you finish the post there: Facebook, X, Threads, Pinterest, Reddit, LinkedIn, Instagram and Instagram Stories, TikTok, and Flickr. Your site never contacts these services.

After you share by hand, the post editor shows a reminder listing the platforms that still need the copy's URL. In 1.0.24 the composer form for recording that URL isn't shown yet; add the link to the post in the block editor instead.

## Expected result

After you connect or configure a service, check the place this page names for it: a syndication chip in the composer, an item in the block editor's Outpost sidebar, or a copy on the destination platform. If a service doesn't show up, see [Troubleshooting](/outpost/troubleshooting/).
