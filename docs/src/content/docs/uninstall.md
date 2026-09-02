---
title: Uninstall
description: "How to deactivate and delete Outpost, what its uninstaller removes, and what stays on your site."
---

How to remove Outpost from your site, what the uninstaller cleans up, and what it leaves behind. Your posts are never touched — anything Outpost published is a standard WordPress post and stays on your site.

## Deactivate the plugin

1. In wp-admin, go to **Plugins → Installed Plugins**.
2. Find **Outpost** and select **Deactivate**.

Deactivation is reversible and deletes no data. It clears Outpost's caches and transients and removes the `/post` rewrite rule, so the composer URL stops responding. Reactivating restores everything.

## Delete the plugin

1. With Outpost deactivated, select **Delete** on the Plugins screen.
2. Confirm the deletion.

WordPress removes the plugin files and runs Outpost's uninstall script.

## What the uninstaller removes — and what it doesn't

Since version 1.0.4, deleting the plugin removes everything Outpost created on your site — on a single site and on every site of a multisite network:

- **All Outpost options** — settings, destination API keys, the generated encryption key, Bridgy silo choices, Telegraph author settings, and the `/post` rewrite bookkeeping.
- **Per-user data** — encrypted service credentials, iOS Shortcut tokens, appearance preferences, and dismissed-notice markers.
- **Outpost's own post meta** — syndication targets and recorded syndication links, POSSE retry state, manual-share and Bridgy logs, Telegraph page links, XFN relationship choices, and check-in place names.
- **Cached data** (transients) and any scheduled syndication retries.

It never removes your posts or media, image alt text, featured images, categories, or another plugin's data — for example a Yoast focus keyphrase Outpost saved on your behalf stays with the post. [Privacy and data](/outpost/privacy-and-data/) lists every option and meta key with its purpose.

## Remove the app from your phone

Deleting the plugin doesn't remove the icon from your home screen. Remove it the way you remove any app: long-press the icon and choose the remove option. Queued offline drafts live in that installed app's browser storage and disappear with it.

## Expected result

After deletion, `/post` returns your theme's 404 page, the Outpost settings screens are gone from wp-admin, and every post you published is still there.
