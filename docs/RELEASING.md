# Releasing Outpost

This guide walks through cutting an Outpost release: bump the version everywhere it lives, update both changelogs, run the checks, commit the build, tag, package the ZIP, and publish a GitHub release. It covers the repository only. Installing the result on a WordPress site is the same as any plugin update, so this guide doesn't cover a deploy.

Only the maintainer tags and publishes releases. A contributor can prepare a release branch (steps 1 through 5) and open a pull request for it.

## 1. Pick the version number

Outpost follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Bump the patch number (1.0.24 → 1.0.25) for fixes and small additions, the minor number for larger features, and the major number for changes that break existing sites or integrations. The examples below use `1.0.25`.

Change the version in the same commit as any rewrite-rule or query-var change. `outpost.php` re-registers and flushes Outpost's rewrite rules only when `OUTPOST_VERSION` differs from the stored `outpost_rewrite_version` option, so a rewrite change without a version bump leaves sites serving the old `/post/*` routes.

## 2. Bump the version in every place it lives

| File | What to change |
|---|---|
| `outpost.php` | The `Version:` line in the plugin header |
| `outpost.php` | `define( 'OUTPOST_VERSION', '…' );`, below the header. It must match `Version:` |
| `readme.txt` | `Stable tag:` |
| `package.json` | `"version"` |
| `docs/src/site-meta.mjs` | `version`, which the docs site shows |

Check that none were missed:

```bash
grep -n "Version:\|OUTPOST_VERSION'" outpost.php
grep -n "Stable tag" readme.txt
grep -n '"version"' package.json
grep -n "version" docs/src/site-meta.mjs
```

`npm run package:wporg` in step 7 refuses to build when the header `Version` and the readme `Stable tag` disagree.

## 3. Update both changelogs

- **`CHANGELOG.md`** follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Move the entries under `## [Unreleased]` into a new `## [1.0.25] - YYYY-MM-DD` section, grouped under `### Added`, `### Changed`, `### Fixed`, and the other Keep a Changelog headings. Leave an empty `## [Unreleased]` heading above it.
- **`readme.txt`** has its own `== Changelog ==` section for WordPress.org readers. Add a `= 1.0.25 =` entry at the top with one `* New:`, `* Changed:`, or `* Fixed:` line per change, written for site owners, not developers. If the release needs a note before people update, add a `= 1.0.25 =` entry under `== Upgrade Notice ==` too.

## 4. Run the checks

Run these from the repository root. All of them must pass:

```bash
composer check        # PHPCS, the §5 audit, PHPStan, then PHPUnit (composer test)
npm test              # Vitest
npm run typecheck     # TypeScript strict mode
npm run lint          # ESLint on pwa/src
npm run lint:wp       # ESLint on the block-editor sidebar in src/
npm run check:dist    # every runtime directory ships in the ZIP
```

With Docker running, run the integration suite as well:

```bash
npm run wp-env:start
npm run test:integration
```

For a release that changes the composer, run the UX test pass in `docs/UX-TESTS.md` against a test site you control, such as the wp-env site at `http://localhost:8888/post/`.

## 5. Build and commit the bundles

```bash
npm run build:all
```

`build:all` runs `npm run build:wp` (the block-editor sidebar, written to `build/`) and then `npm run build` (the token parity check and the Vite composer bundle, written to `build/pwa/`). Both outputs are committed so a clone or ZIP of the repository runs without Node. Commit the version bump, the changelogs, and the rebuilt `build/` together. The project's commit convention uses the `🚀 RELEASE:` prefix for this commit, for example `🚀 RELEASE: 1.0.25`.

Open a pull request, wait for every CI job in `.github/workflows/ci.yml` to pass, and merge it into `main`.

## 6. Tag the release

Tag the merge commit on `main` with the version, prefixed with `v`:

```bash
git switch main
git pull
git tag -a v1.0.25 -m "Outpost 1.0.25"
git push origin v1.0.25
```

## 7. Build the ZIP

```bash
npm run package:wporg
```

`bin/package-wporg.mjs` runs `wp-scripts plugin-zip` (which packs the paths in `package.json`'s `files` list), renames the folder inside the archive to the plugin slug, removes `package.json` and `README.md` from it, and writes `outpost-mobile-publishing-1.0.25.zip` to the repository root. It needs the `zip` and `unzip` command-line tools. `.gitignore` ignores `*.zip`, so the archive never ends up in a commit.

Before you publish, unzip the archive somewhere temporary and confirm the top-level folder is `outpost-mobile-publishing/` and that `build/pwa/` is inside it.

## 8. Publish a GitHub release

This is the intended process going forward. As of 2026-11-08, the repository's Releases page stops at v0.2.0 and its tags stop at v1.0.9, so releases after those were never published there.

```bash
gh release create v1.0.25 outpost-mobile-publishing-1.0.25.zip \
  --title "Outpost 1.0.25" \
  --notes "See CHANGELOG.md for the full list of changes."
```

You can paste the release's `CHANGELOG.md` section into the notes instead. Attach the ZIP from step 7, not GitHub's automatic source archive: the source archive's folder is named after the repository and includes development files that `.distignore` and `package.json`'s `files` list leave out.

## After the release

- Delete the local ZIP, or keep it outside the repository.
- If the docs site describes changed behavior, make sure the pages in `docs/src/content/docs/` match; [MAINTAINING.md](MAINTAINING.md) covers publishing them.
