# Attribution — vendored Clarity scripts

`strip_markdown.py` and `prose_stats.py` are vendored (unmodified) from:

- https://github.com/addyosmani/clarity.git
- MIT License, Copyright (c) 2026 Addy Osmani

They implement the Clarity skill's **lint mode** (`strip_markdown.py draft.md |
prose_stats.py`). Used by `sccrm/ai/clarity_agent.php::clarityAgentLint()`.
See the upstream repo for `SKILL.md`, references, samples and evals
(also imported into OpenViking as the `clarity` skill).
