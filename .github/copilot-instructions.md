# Copilot instructions — pointer

The contract for this repository is `AGENTS.md` at the repository root. Read it before acting
and treat it as authoritative. This file adds nothing and must never grow into a second
contract — the adapter lint (`check_adapters.py`) fails if it exceeds a short pointer.

It exists only for the Copilot surfaces that do not load `AGENTS.md` on their own: Copilot Chat
on GitHub.com and code review inside VS Code. Copilot CLI, the cloud agent, VS Code Chat, and
code review on GitHub.com read `AGENTS.md` directly and do not need this file.
