# Running UX tests with Claude in Chrome

How to run the `[auto]` tests from [UX-TESTS.md](UX-TESTS.md) using the Claude in Chrome browser extension. Claude drives your real browser tab, so it can complete the IndieAuth login with your existing WordPress session, click through every composer mode, and read the console — things a headless script can't do without credential plumbing.

## One-time setup

1. Install the Claude extension in Chrome and sign in to Claude.
2. Pick a test site you control, with Outpost, IndieAuth, and Micropub active. The wp-env development site works: `npm run wp-env:start`, then `http://localhost:8888/` (sign in with the wp-env defaults, `admin` / `password`). Don't use a site with real readers; the run publishes posts.
3. In the same Chrome profile, sign in to that site's wp-admin. The IndieAuth authorize step reuses this session.
4. Open a new tab and open the Claude sidebar.

In the prompts below, replace every `<TEST_SITE>` with your test site's base URL, for example `http://localhost:8888`.

## Run it

Paste the operator prompt below into the Claude sidebar. It tells Claude to fetch the test suite straight from the repo, run the `[auto]` tests in order, and report a results table.

Run in two halves if the session gets long — the prompt supports `Run sections A through F` / `Run sections G through M`.

---

## Operator prompt (copy everything in this block)

```
You are running a UX test pass on the Outpost composer PWA.

TEST SUITE
Fetch and read the suite first:
https://raw.githubusercontent.com/courtneyr-dev/outpost/main/docs/UX-TESTS.md
Run ONLY tests tagged [auto], in order (A1 → M6). Skip every [manual] test
and mark it SKIPPED (manual) in the report. Run A5 (Sign out) LAST.

TARGET
Test site only: <TEST_SITE>/post/
On the first navigation, append ?_cb=<current unix timestamp> to bypass any
page cache. Never navigate to any other WordPress site during this run.

GROUND RULES
1. Every post you create must have content starting with "UX-TEST" so it
   can be found and deleted later. Never post anything else.
2. If a test needs a public URL to reply to / like / bookmark, use
   <TEST_SITE>/ or any post on that test site.
3. For file-upload tests (E1, E2, F3), use any small image you can supply;
   if you cannot attach a file, mark the test BLOCKED (no file available)
   and move on.
4. One test at a time. Verify the expected result stated in the suite
   before recording PASS. If the expectation says a post was created,
   actually open the returned URL and confirm the content is there.
5. On FAIL: take a screenshot, copy any console errors, note the exact
   step that diverged, then CONTINUE with the next test. Do not try to
   fix anything.
6. STOP the whole run and report immediately if: the login flow (A1/A2)
   fails, the page is blank, or you see PHP errors/stack traces — nothing
   downstream is meaningful after that.
7. Check the browser console after each section and note any uncaught
   errors for L5.
8. Keyboard tests (B2, B4): use real key presses, not clicks. For B2,
   Tab to all seven buttons in the "Composer modes" group, select with
   Enter and Space, confirm exactly one has `aria-current="true"`, and
   confirm arrow keys don't move focus or change the selected mode.
9. Mobile layout test (L1): resize the window to ~390px wide, sweep every
   tab for horizontal scroll, then restore the window size.

CLEANUP (after A5)
Go to <TEST_SITE>/wp-admin/edit.php, search "UX-TEST",
and move every matching post to Trash. Report how many you trashed and
how many posts the run created (the numbers should match).

REPORT FORMAT
A single markdown table:
| ID | Result | Notes |
Result is PASS / FAIL / BLOCKED / SKIPPED (manual). Notes stay one line
except for FAILs, which get: what you did, what you expected, what you
saw, console errors if any. After the table, list:
- Total: X pass / Y fail / Z blocked / N skipped
- Posts created vs posts trashed
- Any observations that weren't test failures but felt wrong (slow loads,
  layout oddities, confusing copy) under "UX notes".
```

---

## After the run

1. File each FAIL as a GitHub issue (or fix it in your branch), quoting the row from the results table.
2. Run the `[manual]` tests on a phone: iPhone Safari for K1, K2, and L4; Android Chrome for J2 and K3.

---

# Tier 2 runner — exhaustive variant pass

Run Tier 2 only after Tier 1 passes. It creates ~21 posts, so it runs in **two batches** to keep the agent session manageable. Paste Batch 1; when it reports, paste Batch 2 in a fresh conversation.

## Batch 1 prompt (T2.1 – T2.9, Reply tab)

```
You are running Tier 2 (exhaustive variant pass), Batch 1, on the Outpost
composer. Fetch and read the "Tier 2" section of
https://raw.githubusercontent.com/courtneyr-dev/outpost/main/docs/UX-TESTS.md
then run tests T2.1 through T2.9 in order against
<TEST_SITE>/post/?_cb=<current unix timestamp>.

Hard rules: test site only; every post's text starts with "UX-TEST"; toggle
every syndication chip OFF before each submit; verify each post in
wp-admin (edit.php, newest first) and record its ID; after T2.9, trash all
nine posts via the UX-TEST search and per-row Trash links.

Report: | ID | Result | Post ID | Notes | — then created-vs-trashed counts
and any UX observations. On FAIL: screenshot, console errors, continue.
STOP only if login fails or the page is blank.
```

## Batch 2 prompt (T2.10 – T2.30, everything else)

```
You are running Tier 2 (exhaustive variant pass), Batch 2, on the Outpost
composer. Fetch and read the "Tier 2" section of
https://raw.githubusercontent.com/courtneyr-dev/outpost/main/docs/UX-TESTS.md
then run tests T2.10 through T2.30 in order against
<TEST_SITE>/post/?_cb=<current unix timestamp>.

Hard rules: test site only; every post's text starts with "UX-TEST"; toggle
every syndication chip OFF before each submit; verify each post in
wp-admin and record its ID; T2.23 also requires confirming the
category, tag, slug, and Aside format all landed on the post; for file
uploads (T2.17, T2.22) use any small image, or mark BLOCKED if you cannot
attach one; trash each section's posts before starting the next section.

Report: | ID | Result | Post ID | Notes | — then created-vs-trashed counts
(note that media posts may not text-match the UX-TEST search; reconcile
against the newest-first post list) and any UX observations. On FAIL:
screenshot, console errors, continue. STOP only if login fails or the
page is blank.
```

## After Tier 2

Run the WP-CLI property audit on the reported post IDs to confirm each variant wrote the right Micropub property. The browser can't see post meta, so this is the only check that does. On the wp-env site, run `npm run wp-env -- run cli wp post meta list <ID>` and look for `mf2_` keys; on another test site, run `wp post meta list <ID>` there.
