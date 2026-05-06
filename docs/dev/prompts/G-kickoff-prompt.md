# Phase G overnight kick-off prompt

Paste the entire block below into Claude Code CLI in a fresh session at the Outpost repo (`/Users/crobertson/projects/outpost`). Everything between the opening and closing triple-backticks is the prompt.

---

```
You are Claude Code, working unsupervised overnight on the Outpost WordPress plugin (github.com/courtneyr-dev/outpost). Courtney is asleep. Your job: open one PR per Phase G implementation prompt, G4 through G16, without merging anything.

# Read these first, in this exact order

1. /Users/crobertson/projects/outpost/CLAUDE.md
   — 29 locked FY decisions. All binding. Especially #29 (stack-merge sequencing).

2. /Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/prompts/G-overnight-runbook.md
   — Your single source of truth for HOW to execute. Pre-flight checks, hard rules, per-prompt workflow, branching strategy, status reporting format, stop conditions, end-of-run summary template.

3. /Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/prompts/INDEX.md
   — The 13-prompt manifest (G4 through G16) with order, branches, dependencies.

4. /Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/concepts/posse-expansion-may-2026.md
   — Phase G expansion catalog with platform tables, tier reasoning, the 5 locked Phase G decisions, and the prompt-ordering rationale.

5. /Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/concepts/posse-outbound-may-2026.md
   AND
   /Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/concepts/capture-inbound-may-2026.md
   — Initial Phase G catalog (the 22 platforms specified in F-phase). Context for why Phase G is structured the way it is.

# Where the per-prompt detail lives

Each G-prompt's full implementation spec is at:

    /Users/crobertson/no-icloud/2nd Brain/1. Projects/llm-wiki-outpost/wiki/prompts/G{n}-{slug}.md

Files (read each one when you reach that prompt in the runbook order):

- G4-adapter-primitives.md
- G5-newsletter-posse.md
- G6-newsletter-inbound.md
- G7-newsletter-headless-send.md
- G8-notion.md
- G9-telegraph.md
- G10-scripture.md            (stacked on G4)
- G11-wellness.md
- G12-cycling-climbing.md
- G13-conference.md
- G14-maker.md
- G15-snipd-enhancement.md    (stacked on G4)
- G16-docs-sweep.md            (open last)

Each prompt body has: scope, files to create/modify, locked design decisions, implementation outline, test requirements, acceptance criteria, PR description template, open items.

# Pre-flight, before touching code

The runbook §1 lists 12 pre-flight checks. Run all of them. Specifically, step 9 says:

> Copy the prompt files into the repo: for each G{n}.md in the vault prompts directory, copy to outpost/docs/dev/prompts/. This puts them under version control alongside the F-prompts. Commit them as the first commit of the G4 branch (so they ride along with the first PR).

Also copy G-overnight-runbook.md and INDEX.md from the vault prompts directory to outpost/docs/dev/prompts/ as part of the same commit.

# Hard rules (non-negotiable)

Read runbook §2 before doing anything. Highlights:

- NEVER merge a PR. Courtney merges in the morning.
- NEVER delete a branch. Auto-close-cascade is the FY decision #29 lesson.
- NEVER force-push to main. NEVER push to main directly.
- NEVER modify CLAUDE.md.
- NEVER skip the §5 audit lint CI gate.
- NEVER use forbidden words from runbook §2 (two long banned-word lists; check before every commit message and PR description).
- NEVER exceed 1500 lines per PR. Split into G{n}a + G{n}b if needed.

# How status reporting works

Maintain two git-ignored files in the repo root, format per runbook §5:

- outpost/.overnight-progress.md — running log of every prompt's outcome
- outpost/.overnight-questions.md — blocked decisions Courtney needs to answer in the morning

Do not commit either file. They are for Courtney's morning review.

# Order of execution

Per INDEX.md, run the prompts in this order:

G4 → G5 → G6 → G7 → G8 → G9 → G10 → G11 → G12 → G13 → G14 → G15 → G16

G10 and G15 branch from phase-g/g4-adapter-primitives. Everything else branches from main. G16 opens last.

# Stop conditions

Per runbook §6, stop the run if any of these occur:

- Three consecutive prompts blocked on questions.
- Test suite or build tool stops working repeatedly.
- A git push is rejected suspiciously.
- An existing PR is found on a branch name you would create.
- You finish G16 cleanly.
- 6 hours of total wall-clock runtime.

When you stop, write the final summary template from runbook §7 to .overnight-progress.md.

# Final word from Courtney

If something feels wrong, stop. A clean handoff with 6 PRs and clear questions beats 13 PRs that broke main. The morning review will sort the rest.

Begin the runbook's pre-flight checks now.
```

---

## Why this prompt is structured this way

Each section addresses a concrete failure mode if Claude Code starts cold:

- **Read-list first, in order.** Without this, Claude Code reads the prompt, jumps into G4, and misses the runbook's hard rules. By front-loading the read list with explicit order, the constraints arrive before action.
- **Vault paths fully spelled out.** Claude Code won't guess at `~/Documents/...` or hunt for the vault. The runbook's vault path also appears here so it's redundantly correct.
- **Pre-flight reference is explicit.** Step 9 (copy prompts into repo) is called out because it's the only step that bridges vault → repo, and skipping it would leave Phase G prompts un-versioned.
- **Hard rules summarized inline.** The full list is in the runbook, but a kick-off without inline reminders fails open. The 7 highlighted rules are the ones with the highest blast radius if violated.
- **Order of execution ends the prompt.** Last instruction = first action.

If anything in the runbook contradicts the kick-off prompt, the runbook wins (per its own §1). The kick-off is the front door; the runbook is the SOP.

## What changed from earlier drafts

The runbook used to point Claude Code at "F4-F16 prompts to model on" without acknowledging that G4-G16 prompt bodies didn't exist. They exist now, in `wiki/prompts/`. The runbook still references F4-F16 as structural models, but the per-prompt content lives in the new G-prompt files.

## Morning checklist for Courtney

When you wake up:

1. `cat /Users/crobertson/projects/outpost/.overnight-progress.md`
2. `cat /Users/crobertson/projects/outpost/.overnight-questions.md`
3. Open the GitHub PR list and skim PR descriptions (especially the "Locked design decisions" section in each — that's where Claude Code documented choices it made under uncertainty).
4. Merge order per INDEX.md and runbook §7.
