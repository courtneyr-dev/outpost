# Phase G overnight runbook

You are Claude Code, working unsupervised overnight on the Outpost project (`github.com/courtneyr-dev/outpost`). Courtney is asleep. Your job is to step through Phase G implementation prompts G4 → G16 sequentially, opening one PR per prompt, **without merging anything**.

This runbook is your single source of truth. If anything in here conflicts with another file, this runbook wins for the duration of this overnight run. Read it end-to-end before starting.

---

## 1. Pre-flight checks (do these first, in order)

Run all of these before touching any code. If any fail, write the failure to `outpost/.overnight-progress.md` and stop.

1. `cd /Users/crobertson/projects/outpost`
2. `git status` — must be clean. If dirty, stop.
3. `git checkout main && git pull --ff-only origin main` — must succeed.
4. `gh auth status` — must show authenticated.
5. Read `outpost/CLAUDE.md` end to end. The 29 locked FY decisions (especially #29 stack-merge sequencing) are binding.
6. Read `/Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/prompts/INDEX.md` to confirm the prompt list is intact. Read each G{n}.md file in the same directory.
7. Read `/Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/concepts/posse-expansion-may-2026.md` end to end. §0 cross-cutting findings, §11 prompt summaries, and the per-category platform tables are all required context.
8. Read `/Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/concepts/posse-outbound-may-2026.md` and `capture-inbound-may-2026.md` for context on the initial Phase G catalog (the 22 platforms already specified in F-phase).
9. **Copy the prompt files into the repo:** for each `G{n}.md` in the vault prompts directory, copy to `outpost/docs/dev/prompts/`. This puts them under version control alongside the F-prompts. Commit them as the first commit of the G4 branch (so they ride along with the first PR).
10. Read `outpost/package.json`, `outpost/composer.json`, and `outpost/.github/workflows/` to discover the actual test/lint/build commands. Use those exact commands; do not invent.
11. Confirm the §5 audit lint CI workflow exists and is gating PRs (it should — that's F4).
12. Create `outpost/.overnight-progress.md` and `outpost/.overnight-questions.md`. These are git-ignored working files; do not commit them.

---

## 2. Hard rules (NEVER violate)

- **Never merge a PR.** Courtney merges in the morning. You only open PRs.
- **Never delete a branch.** Auto-close-cascade lesson from FY decision #29. If a stacked PR's base branch is deleted before the dependent PR is retargeted, GitHub auto-closes the dependent. You do not delete branches under any circumstance during this run.
- **Never force-push to `main`.** Never push directly to `main`. All work is on feature branches.
- **Never modify `CLAUDE.md`.** If you find yourself wanting to add a 30th locked decision, write the question to `.overnight-questions.md` and skip the prompt that triggered it.
- **Never modify the Phase G catalog files in the vault.** Same rule — write a question.
- **Never skip §5 audit lint.** It is a required CI gate (F4). If it fails on your PR, fix the code, not the lint.
- **Never use forbidden words.** Courtney maintains two banned-word lists in her writing style. Do not use any of these in commit messages, PR descriptions, code comments, or documentation:
  - List 1: delve, hidden, discover, captivate, intrigue, mystery, unlock, unleash, harness, leverage, synergy, robust, seamless, cutting-edge, game-changer, elevate, empower, foster, navigate, landscape, realm, tapestry, pivotal, paramount, streamline, spearhead, groundbreaking, transformative, revolutionize, holistic, innovative, disruptive, unpack, curate, optimize, paradigm, nestled
  - List 2: ecosystem (non-tech contexts), stakeholder, bandwidth (non-tech contexts), low-hanging fruit, move the needle, boil the ocean, at the end of the day, it's worth noting, in today's world, circle back, deep dive, hit the ground running, hard stop, lean in, take things offline, decisioning, decision tree, pivoting, space (as jargon), having agency, workslop, agentic AI, AI agents, digital teammates, unbossing, flatter (org-speak), ghost growth, office frogs, coffee badging, job hugging, quiet cracking
  - "WordPress ecosystem" and "network bandwidth" are fine — those are tech contexts.
- **Never assume user-facing copy.** If a prompt requires UI strings, button labels, error messages, or marketing copy not specified in the prompt body or catalog, write a question and skip.
- **Never modify staging.** Staging deployment is `~/projects/staging-courtneyr-dev` with gd-wordpress-deployer. Do not touch it overnight.
- **Never exceed 1500 lines per PR.** If your diff grows past that, split the prompt into two PRs (e.g., G4a and G4b) and document the split.
- **The hard contract holds: "plugin owns layout, theme owns paint."** If a prompt seems to push hue/color decisions into the plugin, that's a signal to stop and write a question.
- **WordPress.org platform-agnostic requirement.** No vendor-lock-in code paths. No GoDaddy-only assumptions in plugin code (assumptions in docs site are fine).

---

## 3. Per-prompt workflow

For each G-prompt, the prompt body (`outpost/docs/dev/prompts/G{n}-{slug}.md`) is your authoritative spec. The runbook tells you HOW to execute; the prompt body tells you WHAT to build. Per prompt, do this in order:

1. **Read the prompt body completely.** Re-read its "Design decisions locked" section.
2. **Verify prerequisites.** Check the prompt's `depends:` frontmatter. If a dependency is in `phase-g/g{n}-…` branch but not yet merged, decide stacking vs. wait (see §4).
3. **Create the branch** per prompt's `branch:` frontmatter. `git checkout -b phase-g/g{n}-{slug}` from the appropriate base.
4. **Implement.** Match existing F-phase code style, file layout, namespace conventions, PHPDoc conventions. The prompt body's "Files to create or modify" section is your file checklist.
5. **Write integration tests** per the prompt's "Tests" section. Pick up any of the 80 skipped wp-env stubs that the prompt names; wire them up. Add new tests where stubs don't cover.
6. **Run the full local test suite.** All tests must pass before commit.
7. **Run the §5 audit lint locally.** Must pass before commit.
8. **Write the docs page(s)** per the prompt's file list. Include open-source alternative call-outs where the prompt specifies (e.g., G12 OpenBeta, G13 Pretalx).
9. **Commit in small logical commits.** Conventional Commits style.
10. **Push the branch.** `git push -u origin phase-g/g{n}-{slug}`.
11. **Open the PR via `gh pr create`** using the prompt's "PR description template" verbatim, filling in the placeholders.
12. **Wait for CI.** Poll `gh pr checks` until pass or fail or 20 min timeout. Document outcome in progress file.
13. **If CI fails:** debug, push fixes, repeat up to 3 attempts. After 3 failed CI runs, mark the prompt blocked, write to `.overnight-questions.md`, move to the next independent prompt.
14. **Record success in `.overnight-progress.md`** with timestamp, PR number, branch, base, line count, test count.
15. **Move to next prompt.**

---

## 4. Branching and stacking strategy

Per FY decision #29 (stack-merge sequencing): retarget BEFORE deleting. You're not deleting anything tonight, but you may be stacking.

**Stacks in this run:**

- G10 (scripture cluster) base = `phase-g/g4-adapter-primitives`
- G15 (Snipd enhancement) base = `phase-g/g4-adapter-primitives`

Everything else branches from `main`. G7 (newsletter headless-send) shares some scaffolding with G5 (newsletter POSSE-outbound) but functionally independent — branch G7 from `main`, expect possible merge conflict in shared credential storage layer, document the conflict points in the PR description so Courtney knows to merge G5 first.

**G16 (docs sweep) is last.** Branch G16 from `main` after every other G-prompt's PR is open. G16's diff references the other PRs' adapter docs.

**PR description mandatory section: "Merge order"** — for stacked PRs, explicitly state "Stacked on PR #X — merge that first". For independent PRs, state "Independent — can merge in any order relative to other Phase G PRs".

---

## 5. Status reporting

Maintain `outpost/.overnight-progress.md` as a running log. Format per entry:

```
## G4 — Adapter primitives v1
- Started: 2026-05-06 22:14 ET
- Branch: phase-g/g4-adapter-primitives
- Base: main
- Status: PR opened
- PR: #42 https://github.com/courtneyr-dev/outpost/pull/42
- CI: passed in 4m 12s
- Lines: 1247
- Tests: 38 new, 12 picked up from skipped stubs
- Notes: [any deviations from prompt spec]
```

Maintain `outpost/.overnight-questions.md` for blocked decisions. Format per entry:

```
## Q1 — G7 Beehiiv Send API authentication
- Asked: 2026-05-06 23:42 ET
- Triggered by: G7 implementation
- Question: [the actual question]
- Action taken: skipped G7, moved to G8
- Recommended default if you want me to retry: [a default Claude Code would have used]
```

Both files are git-ignored. Do not commit them. They are for Courtney's morning review.

---

## 6. Stop conditions

Stop the entire overnight run and write a final summary to `.overnight-progress.md` if any of these occur:

- Three consecutive G-prompts blocked on questions.
- A test suite or build tool stops working repeatedly.
- A `git push` is rejected for a reason that suggests force-push protection or branch protection conflicts.
- You discover an existing PR on the same branch name. Stop, do not overwrite, document.
- You finish G16 cleanly. Write the final summary and stop.
- You hit 6 hours of total wall-clock runtime. Write summary and stop.

The final summary at end of run must include:

- List of PRs opened (number, title, branch, base, CI status, line count)
- List of PRs blocked (with question reference)
- List of skipped prompts (with reason)
- Recommended morning merge order
- Any anomalies worth Courtney's attention

---

## 7. End-of-run summary template

Append this to `.overnight-progress.md` when stopping:

```
# Final summary — Phase G overnight run

## Run window
- Started: {timestamp}
- Stopped: {timestamp}
- Total wall-clock: {hours}h{minutes}m

## PRs opened
| PR # | Branch | Base | CI | Lines | Tests | Status |
|---|---|---|---|---|---|---|
| {n} | {branch} | {base} | passed/failed | {lines} | {count} | open / blocked |

## Recommended morning merge order
1. G4 (PR #X) — must merge first; G10 and G15 are stacked on this
2. G5, G6, G7, G8, G9, G11, G12, G13, G14 — independent, any order
3. G10 (PR #Y) — after G4 merges, retarget to main per FY decision #29 BEFORE deleting G4's branch
4. G15 (PR #Z) — same retarget rule as G10
5. G16 (PR #W) — last; may need follow-up commit if other PRs took adjustments during your review

## Blocked / skipped
- {prompt}: {reason} — see Q{n} in .overnight-questions.md

## Anomalies
- {anything Courtney should know about}

## Files Courtney should read first thing
1. .overnight-progress.md (this file)
2. .overnight-questions.md
3. Any PR descriptions flagged above
```

---

## 8. Final word

If something feels wrong, stop. A clean handoff with 6 PRs and clear questions is better than 13 PRs that broke main or got mis-stacked. Courtney would rather wake up to "I got 8 done, 3 blocked, here's what I need" than "I got all 13 done but somehow main is broken."

Begin with the pre-flight checks. Good night.
