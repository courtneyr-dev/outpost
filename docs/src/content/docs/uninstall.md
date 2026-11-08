---
title: Uninstall
description: "How to deactivate and delete Outpost, what its uninstaller removes, and what stays on your site."
---

How to remove Outpost from your site, what the uninstaller cleans up, and what it leaves behind. Your posts are never touched — anything Outpost published is a standard WordPress post and stays on your site.

## Deactivate the plugin

1. In wp-admin, go to **Plugins → Installed Plugins**.
2. Find **Outpost** and select **Deactivate**.

Deactivation is reversible and deletes no data. It flushes WordPress's rewrite rules, so `/post` stops serving the composer. Reactivating restores it.

## Delete the plugin

1. With Outpost deactivated, select **Delete** on the Plugins screen.
2. Confirm the deletion.

WordPress removes the plugin files and runs Outpost's uninstall script.

## What the uninstaller removes — and what it doesn't

Deleting the plugin runs Outpost's uninstaller on a single site and on every site of a multisite network. In version 1.0.24 it removes:

- **Outpost options:** settings, destination API keys, the generated encryption key, Bridgy silo choices, Telegraph settings, and the `/post` rewrite bookkeeping.
- **Per-person data:** encrypted service credentials, iOS Shortcut tokens, Light, Dark, or System choices, token overrides, and dismissed-notice markers.
- **Outpost's own post meta:** syndication targets and recorded syndication links, POSSE retry state, manual-share and Bridgy logs, Telegraph page links, XFN relationship choices, and check-in place names.
- **Cached data** (transients) and any scheduled syndication retries.

Two small records added in 1.0.24 stay behind: each person's style source choice (`outpost_appearance_source` user meta) and the site's `outpost_appearance_schema` option. Neither holds content or credentials. To remove them with WP-CLI:

```bash
wp option delete outpost_appearance_schema
wp user list --field=ID | xargs -I % wp user meta delete % outpost_appearance_source
```

It never removes your posts or media, image alt text, featured images, categories, activity details attached to posts, or another plugin's data. For example, a Yoast focus keyphrase Outpost saved on your behalf stays with the post. [Privacy and data](/outpost/privacy-and-data/#what-uninstalling-removes) lists every option and meta key with its purpose.

## Remove the app from your phone

Deleting the plugin doesn't remove the icon from your home screen. Remove it the way you remove any app: long-press the icon and choose the remove option. Queued offline drafts live in that installed app's browser storage and disappear with it.

## Expected result

After deletion, `/post` returns your theme's 404 page, the Outpost settings screens are gone from wp-admin, and every post you published is still there.
