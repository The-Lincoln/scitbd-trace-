---
name: nova-path-traversal-defense
description: "Validate user/LLM-supplied path values against containment boundary (Nova promoted, conf 0.98)."
---

# Skill: Path Traversal Defense
Status: promoted
Confidence: 0.98
Source: council-review-round-5

## Rule
Any user/LLM-supplied value used in file path construction must be validated: reject "..", validate against containment boundary.

## Evidence
S-001, T-002, A-002: worldId, rawPath, project_name all allowed path traversal from untrusted sources.

## Application
After path.resolve(), verify result.startsWith(allowedBaseDir + path.sep). For IDs, enforce UUID regex.
