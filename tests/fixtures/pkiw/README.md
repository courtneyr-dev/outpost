# Post Kinds mood vocabulary fixtures

Response bodies for `GET /wp-json/post-kinds-indieweb/v1/moods`, the route
Post Kinds for IndieWeb adds in PKIW #211 (1.8.2-rc.1). The contract is
`docs/integrations/mood-labels.md` in that repo.

- `moods-en_US.json`: "Mood label spelling" set to English (United States).
- `moods-en_GB.json`: set to English (United Kingdom).

Labels and variants copy `PKIW\Mood_Vocabulary::definitions()` and
`EN_GB_VARIANTS` on the `feature/spelling-preferences` branch. `version` is
the md5 of `[spelling, locale, moods]`, as PKIW computes it. Outpost treats
`version` as opaque.

The PWA tests (`pwa/src/lib/pkiw-moods.test.ts`,
`pwa/src/components/modes/life-mode.test.tsx`) and the PHP stub
(`mood-vocabulary-stub.php`) read these files, so both sides test against one
copy of the response.
