# Staging Deployment

Outpost is deployed to staging via the same pattern as the `courtneyr-child` theme: a git submodule pinned to a specific commit, deployed by the [`gd-wordpress-deployer`](https://github.com/godaddy-wordpress/gd-wordpress-deployer) GitHub Action that lives in [`staging-courtneyr-dev`](https://github.com/courtneyr-dev/staging-courtneyr-dev).

## Topology

```
github.com/courtneyr-dev/outpost                  ← this repo
                  │
                  │ pinned at SHA via submodule
                  ▼
github.com/courtneyr-dev/staging-courtneyr-dev    ← deploy repo
        plugins/outpost/                          ← submodule pointer
                  │
                  │ rsync on push to main
                  ▼
qkf.b0d.myftpupload.com:html/wp-content/plugins/outpost/
```

## First-time setup (Session A0 — done once)

From `~/projects/staging-courtneyr-dev/`:

```bash
cd ~/projects/staging-courtneyr-dev
git submodule add https://github.com/courtneyr-dev/outpost.git plugins/outpost
git add .gitmodules plugins/outpost
git commit -m "Add outpost submodule (Session A0 scaffold)"
git push origin main
```

The push triggers `gd-wordpress-deployer`. Watch it land:

```bash
gh run watch -R courtneyr-dev/staging-courtneyr-dev
```

## Routine deploy — bumping the submodule pointer

When Outpost ships a new commit to `main`, bump the submodule pin in `staging-courtneyr-dev`:

```bash
cd ~/projects/staging-courtneyr-dev/plugins/outpost
git pull origin main
cd ../..
git add plugins/outpost
git commit -m "Bump outpost to <short-sha> (<one-line summary>)"
git push origin main
```

Pin to specific SHAs, never to `main`-as-a-floating-ref. The deploy Action validates JSON and PHP-lints before rsyncing; failed validation blocks the deploy.

## What ships

The `gd-wordpress-deployer` Action rsyncs the **contents** of `staging-courtneyr-dev/plugins/outpost/` to the server. That means everything in the working tree — including `vendor/` and `build/` — gets deployed. Implications:

- **`build/` must be committed at the time of deploy** for production-shaped staging tests, since the rsync reflects the working tree. Local development uses `npm run dev` (Vite serves at `localhost:5173`); staging needs the production build.
- **`vendor/` must be committed** if the plugin uses Composer runtime dependencies. For Outpost, runtime dependencies are intentionally zero through Phase B; we'll revisit when needed.
- **Dev artefacts must be `.distignore`d** to stay out of the deployed tree. (Not yet authored — added in Phase I.)

For Session A0, neither `build/` nor `vendor/` matters because no PWA code or runtime PHP dependencies exist yet.

## CI/CD configuration on the server side

Configured in `staging-courtneyr-dev`. Courtney owns this; the `outpost` repo doesn't need to touch it.

- Deploy user: `git_deployer_b643f954f8_41451`
- Public key registered to that user: `~/.ssh/courtneyr_staging_deploy.pub`
- Private key: `staging-courtneyr-dev` GitHub secret `PRIVATE_KEY`
- Trigger: every push to `main` of `staging-courtneyr-dev`, or manual `workflow_dispatch`

## Rolling back

```bash
cd ~/projects/staging-courtneyr-dev
git revert <bad-bump-sha>
git push origin main
```

The next deploy rsyncs the previous Outpost SHA back into place. Submodule revert is safer than reverting Outpost itself, because the deploy mechanism only sees the staging repo's pointer — the upstream `outpost` history stays linear.

## Local development vs staging

Local development happens at `~/projects/outpost/`. Symlinking it into a local WordPress install (or using `wp-now`) is the fastest dev loop. Staging is for integration tests against the actual GoDaddy environment, not iterative development.

## Production deployment

Production (`courtneyr.dev`) deployment is a separate concern. When Outpost ships v1.0, it goes through:

1. WordPress.org plugin directory (Phase I).
2. Manual install on production from the WP plugin search.

Production does not use the `gd-wordpress-deployer` submodule mechanism — that's staging-only.
