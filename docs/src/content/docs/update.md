---
title: Update Outpost
description: "Move an Outpost install to a newer version from GitHub, with git or by uploading a ZIP, and check that the composer picked up the update."
---

How to move your Outpost install to a newer version. Outpost isn't in the WordPress.org plugin directory yet, so WordPress doesn't show update notices for it. Check the [changelog](https://github.com/courtneyr-dev/outpost/blob/main/CHANGELOG.md) for new versions, then update the same way you installed.

## Before you start

- Find the version you run now: wp-admin → **Plugins**, under **Outpost Mobile Publishing**. The composer's **About** tab shows it too.
- Find your plugin folder name: `wp-content/plugins/` holds a folder named `outpost-mobile-publishing`, `outpost`, or `outpost-main`, depending on how you installed. The update has to land in that same folder, or WordPress treats it as a second plugin.
- Read the changelog entries between your version and the new one. Entries marked **Changed** describe anything that behaves differently after the update.

## Update with git

If you installed with `git clone`:

```bash
cd wp-content/plugins/outpost-mobile-publishing
git pull
```

Replace `outpost-mobile-publishing` with your folder name. The compiled files are committed, so there's no build step.

## Update by uploading a ZIP

If you installed from a ZIP:

1. On the [Outpost repository page](https://github.com/courtneyr-dev/outpost), choose **Code** → **Download ZIP**.
2. Unzip it and rename the `outpost-main` folder to your plugin folder name. Zip that folder again.
3. In wp-admin, go to **Plugins** → **Add New Plugin** → **Upload Plugin**, choose your ZIP, and select **Install Now**.
4. WordPress says the plugin is already installed and compares the two versions. Select **Replace current with uploaded**.

Your settings, connected accounts, and posts stay in place: they live in the database, not in the plugin folder.

## After the update

1. Reload the composer at `/post/` on each device you use. The installed app picks up the new version when it reloads.
2. If the composer still looks like the old version, clear your host's page cache and any CDN cache, then reload again. [Troubleshooting](/outpost/troubleshooting/#stale-composer-after-an-update) covers this case.

You don't need to re-save permalinks. When Outpost sees a new version number, it re-registers its `/post` routes on the next page load.

## Confirm it worked

- wp-admin → **Plugins** shows the new version number under Outpost Mobile Publishing.
- The composer's **About** tab shows the same version.
- You see one Outpost entry on the Plugins screen, not two. If you see two, the update went to a different folder: deactivate and delete the older one, and keep using the folder name of the one you keep.

Unsent drafts on your phone survive the update. They're stored on the device and reopen when you sign in again as the same site.
