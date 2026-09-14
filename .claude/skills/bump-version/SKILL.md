---
name: bump-version
description: Use when the user types `/bump-version` (optionally with a target like `/bump-version 1.13.0`) or asks to bump, prepare, or set the next version of Novamira (Free) in this repository, including Italian phrasings like "fai il bump", "prepara la 1.x.y", "alza la versione". Not for novamira-pro, which has its own bump skill.
---

# Bump version (Novamira Free)

Three-file edit: plugin header, version constant, changelog header. Never touches `release-info.json`.

## Preconditions

1. Scope check. Stop if this fails:
   ```bash
   test -f ./novamira.php && test -f ./includes/compatibility.php && test -f ./release-info.json && test -f ./CHANGELOG.txt
   ```
   On failure: "bump-version here is scoped to the Novamira Free plugin root; cd there and re-run."

2. Dirty tree: `git status --porcelain`. If non-empty, warn that the bump will mix with unstaged changes. Do not block.

## Step 1: target version

- **Argument given**: use it verbatim. It must match `^[0-9]+\.[0-9]+\.[0-9]+(-(rc|beta|alpha)[0-9]+)?$`, else refuse naming the string.
- **No argument**: read the last shipped version from `release-info.json` and propose the next patch (`1.12.3` → `1.12.4`). Ask the user to confirm or give another version before editing anything. Do not guess minor or major.

## Step 2: check against release-info.json

```bash
LAST="$(python3 -c 'import json;print(json.load(open("release-info.json"))["versions"][0]["version"])')"
```

- Target equals LAST: refuse, it already shipped.
- Target lower than LAST (compare numerically per segment, prerelease suffix sorts below the plain version): ask the user to confirm explicitly before continuing.
- Target higher: continue.

## Step 3: current state

```bash
sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' novamira.php
sed -n "s/.*NOVAMIRA_VERSION.*value:[[:space:]]*'\([^']*\)'.*/\1/p" includes/compatibility.php
```

These are the exact extractions `bin/release` uses in its "check version in code" step, so both must end up equal to the target. If they already differ from each other, tell the user before editing.

## Step 4: edit the two version strings

Use the Edit tool, one surgical replacement per file:

- `novamira.php`: the ` * Version: <old>` docblock line.
- `includes/compatibility.php`: `define(constant_name: 'NOVAMIRA_VERSION', value: '<old>');`

There is no `package.json` version, `readme.txt`, or other version string to update. Do not search-and-replace the old version globally: it also appears in `release-info.json` and in past changelog headers.

## Step 5: CHANGELOG.txt header

The top block holds the unreleased entries. It arrives in one of three shapes; handle each:

| First line | Action |
|---|---|
| `Unreleased` | Replace that line with `v<TARGET> - <YYYY/MM/DD>` |
| An entry (`* Fix:` etc.) with no header | Prepend `v<TARGET> - <YYYY/MM/DD>` as a new first line |
| `v<TARGET> - ...` | Leave it |
| `v<other> - ...` | Stop: there is no unreleased block. Ask whether the changelog still needs writing (see the `changelog` skill) |

Date from `date +%Y/%m/%d`.

Then ensure the block ends with `* Minor Fixes` as its last entry, right before the blank line. Add it when missing. Every shipped release in this changelog closes with it, and the release script prompts for it in interactive mode.

Do not write or reword the entries themselves; that is the `changelog` skill's job.

## Step 6: summary

```
Bumped to v<TARGET>:
  novamira.php               : Version header  <old> → <TARGET>
  includes/compatibility.php : NOVAMIRA_VERSION  <old> → <TARGET>
  CHANGELOG.txt              : header v<TARGET> - <date>
  CHANGELOG.txt              : added "* Minor Fixes"

Last shipped per release-info.json: v<LAST>

Not done: commit, tag, release. Next: review `git diff`, then the `release` skill.
```

Omit any line whose change did not happen (file already at target, "Minor Fixes" already present).

## Not in scope

- `release-info.json`: written only by the release pipeline (CLAUDE.md).
- Commit, tag, push, build, mago checks (nothing executable changes).
- Changelog entry content.

## Related skills

- `changelog`: draft the entries this skill stamps.
- `release`: `bin/release VERSION --non-interactive`, which re-verifies the header, the constant, and the changelog header.
