# Phase G implementation prompts — INDEX

Drafted 2026-05-05 for overnight Claude Code execution. Each prompt is self-contained: a single PR's worth of scope with locked design decisions, concrete file paths, and acceptance criteria.

## Order of execution

Per `G-overnight-runbook.md` §7:

| # | Prompt | Branch | Base | Depends |
|---|---|---|---|---|
| G4 | [Adapter primitives v1](G4-adapter-primitives.md) | `phase-g/g4-adapter-primitives` | main | none |
| G5 | [Newsletter POSSE-outbound cluster](G5-newsletter-posse.md) | `phase-g/g5-newsletter-posse` | main | none |
| G6 | [Newsletter RSS inbound cluster](G6-newsletter-inbound.md) | `phase-g/g6-newsletter-inbound` | main | none |
| G7 | [Newsletter headless-send cluster](G7-newsletter-headless-send.md) | `phase-g/g7-newsletter-headless-send` | main | none (shares scaffolding with G5) |
| G8 | [Notion outbound + inbound](G8-notion.md) | `phase-g/g8-notion` | main | none |
| G9 | [Telegraph outbound](G9-telegraph.md) | `phase-g/g9-telegraph` | main | none |
| G10 | [Scripture inbound cluster](G10-scripture.md) | `phase-g/g10-scripture` | `phase-g/g4-adapter-primitives` | **G4** |
| G11 | [Wellness API cluster](G11-wellness.md) | `phase-g/g11-wellness` | main | none |
| G12 | [Cycling + climbing cluster](G12-cycling-climbing.md) | `phase-g/g12-cycling-climbing` | main | none |
| G13 | [Conference / CFP inbound](G13-conference.md) | `phase-g/g13-conference` | main | none |
| G14 | [Maker cluster](G14-maker.md) | `phase-g/g14-maker` | main | none |
| G15 | [Snipd enhancement](G15-snipd-enhancement.md) | `phase-g/g15-snipd-enhancement` | `phase-g/g4-adapter-primitives` | **G4** |
| G16 | [Docs sweep + alternatives page](G16-docs-sweep.md) | `phase-g/g16-docs` | main | all others (open last) |

## How Claude Code uses these

Per the runbook pre-flight, Claude Code will:

1. Copy these prompts from the vault path `1. Projects/llm-wiki-outpost/wiki/prompts/G*.md` into the repo at `outpost/docs/dev/prompts/` for version control.
2. For each G-prompt in order: read the prompt body, follow it end-to-end, open one PR.
3. When a prompt has open items, Claude Code writes to `.overnight-questions.md` and either skips or proceeds with the documented default.

## Prompt structure (every G{n}.md follows this)

Each prompt has:

- **Frontmatter** — title, branch, base, depends
- **Scope** — what this PR delivers, in one paragraph
- **Files to create or modify** — concrete paths under `outpost/`
- **Design decisions locked** — numbered list of decisions Claude Code must follow without asking
- **Implementation outline** — bullets covering the code shape
- **Tests** — unit + integration, with wp-env wiring picked up from the 80 skipped stubs where applicable
- **Acceptance criteria** — checklist that must all pass before PR opens
- **PR description template** — what to put in the PR body
- **Open items** — things still TBD; Claude Code logs these and proceeds with documented defaults

## Naming conventions used across all prompts

- PHP namespace: `Outpost\Adapters\{Platform}` for platform adapters; `Outpost\Adapters\Primitives` for shared primitives
- File path: `outpost/includes/adapters/class-{platform}-adapter.php`
- REST namespace: `outpost/v1/g/{platform}/` for new endpoints
- Filter prefix: `outpost_{platform}_*` for adapter-specific filters
- Action prefix: `outpost_{platform}_*` for adapter-specific actions
- Option key prefix: `outpost_{platform}_*` for stored options
- Test path: `outpost/tests/integration/adapters/test-{platform}-adapter.php`
- Docs path: `outpost/docs/adapters/{platform}.md`

If existing F-phase code uses different conventions, Claude Code matches the existing convention rather than the one above. The runbook §3 step 4 makes this explicit.

## Cross-reference

- Phase G expansion catalog (specs and tier reasoning): `wiki/concepts/posse-expansion-may-2026.md`
- Initial Phase G catalog (the 22 platforms before this expansion): `wiki/concepts/posse-outbound-may-2026.md` and `wiki/concepts/capture-inbound-may-2026.md`
- Overnight runbook (safety rails, branching, PR rules): `wiki/prompts/G-overnight-runbook.md` (also delivered to repo)
- 29 locked FY decisions: `outpost/CLAUDE.md` (in repo, not vault)
