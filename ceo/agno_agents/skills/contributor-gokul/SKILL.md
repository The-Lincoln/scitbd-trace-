---
name: contributor-gokul
description: Gokul-ram-j contributor routing for Agno agents — stack match + assignment proposals, human assigns.
upstream: https://github.com/The-Lincoln/Gokul-ram-j.git
vendor: external/Gokul-ram-j
wrappers: [ceo/contributor_gokul.py, autoflows/app/services/ContributorGokul.php, sccrm/ai/contributor_gokul_agent.php]
---

# Contributor Gokul — Agno skill

See `ceo/.agents/skills/contributor-gokul/SKILL.md` (canonical).

Agno usage:
```python
import contributor_gokul as C  # ceo/contributor_gokul.py
C.status()                      # profile vendored?
C.match_task("Fix Expo mobile crash")  # hits + fit_score
```

PHP: `ContributorGokul::matchTask($text)`, `contributorGokulPropose($db,$ticketId)`.
Rules: proposals only, human assigns.
