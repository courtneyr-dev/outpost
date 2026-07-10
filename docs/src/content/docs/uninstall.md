---
title: Uninstall
description: "How to deactivate and delete Outpost, exactly what its uninstaller removes today, and which settings and posts stay on your site."
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

The current uninstall script removes one database row: the `outpost_rewrite_version` option (internal bookkeeping for the `/post` URL).

Everything else Outpost stored **remains in your database** after deletion:

- Settings options (`outpost_settings*`) and the generated encryption key option, if one was created.
- Encrypted service credentials (`outpost_creds_*` options and user meta).
- Per-post syndication records and activity snapshots (post meta).
- The iOS Shortcut token and appearance preference (user meta).

The uninstall script itself marks these as future cleanup. If you want a full purge, remove those rows manually — [Privacy and data](/outpost/privacy-and-data/) lists every option and meta key with its purpose. The limited cleanup is flagged for the maintainer in the repository's documentation plan.

## Remove the app from your phone

Deleting the plugin doesn't remove the icon from your home screen. Remove it the way you remove any app: long-press the icon and choose the remove option. Queued offline drafts live in that installed app's browser storage and disappear with it.

## Expected result

After deletion, `/post` returns your theme's 404 page, the Outpost settings screens are gone from wp-admin, and every post you published is still there.
