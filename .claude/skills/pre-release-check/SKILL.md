---
name: pre-release-check
description: Pre-release verification suite for the novamira (Free) WordPress plugin, in this repository. Use when the user types `/pre-release-check` or asks "controlla prima della release", "pre release check", "is novamira ready to ship", "siamo pronti per la release". Runs mago format/lint/analyze, the PHPUnit suite, scans the diff since the last git tag for personal paths/secrets/forbidden mentions/private or reviewer-facing comments, builds the release zip and verifies no internal files leaked, then prints a single PASS/FAIL summary table. Scoped to novamira (Free) only; novamira-pro has its own skill.
---

# Pre-release Check (novamira)

Run before tagging a release of **novamira** (the Free plugin in this repository, `novamira.php`). Four checks; every one always runs.

## Preconditions

```bash
test -f ./novamira.php && test -f ./mago.toml || { echo "not in novamira plugin root"; exit 1; }
LAST_TAG="$(git describe --tags --abbrev=0)" || { echo "no baseline tag"; exit 1; }
DIFF_RANGE="${LAST_TAG}..HEAD"
```

If `git status --porcelain` is non-empty, mention to the user that uncommitted changes won't be in the zip (the build uses `git ls-files`). Don't block.

## Step 1 — mago

Three checks, each FAIL if non-zero exit or any reported issue. Use the make targets (CLAUDE.md requires this, and they also verify the pinned Mago version):

```bash
make mago-format-check   # if FAIL: tell user to run `make mago-format` and re-check
make mago-lint           # zero errors/warnings/help required
make mago-analyze        # zero issues required
```

Never run `make mago-format` (the in-place fixer) automatically. If `mago-format-check` fails, status `FAIL` with the affected file list; the user re-runs after fixing.

## Step 2 — Tests

```bash
make test-unit   # ./vendor/bin/phpunit — no WordPress install, no network; this is what bin/release runs
```

FAIL if any test fails or errors, with the failing test names.

## Step 3 — Internal-reveal, private-comment & private-info scan

Grep the diff since the last tag for things that shouldn't ship. Unlike novamira-pro, this plugin's own purpose is connecting AI clients (Claude, ChatGPT, Codex, Grok, ...) to WordPress, so mentioning those names in code or UI strings is expected and must NOT be flagged — only scan for genuine leaks. The SPDX copyright header (`// SPDX-FileCopyrightText: ... <dev@novamira.ai>`) on every file, and any `@novamira.ai` / `@ovation.*` address, are the project's own boilerplate and must NOT be flagged either — exclude those lines before matching.

```bash
git diff "${DIFF_RANGE}" -- '*.php' '*.js' '*.css' '*.md' '*.txt' \
  | grep -nE '^\+' \
  | grep -v -E 'SPDX-FileCopyrightText|@(novamira\.ai|ovation\.[a-z.]+)' \
  | grep -E -i '(Novamira[[:space:]]+Hub|/Users/|/home/[a-z]+/|/Volumes/|api[_-]?key[[:space:]]*[=:][[:space:]]*["'"'"'][^"'"'"']+|bearer[[:space:]]+[A-Za-z0-9_-]{20,}|sk_(test|live)_|AKIA[A-Z0-9]{16}|eyJ[A-Za-z0-9_-]+\.eyJ|TODO|FIXME|XXX|HACK:|reviewer|revisore|per il reviewer|nota per|please review|rivedi (con|prima)|controllare con|chiedi a [a-z]+|ask [a-z]+ about|remove before (merge|release)|non per la release|not for release|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[a-z]{2,}|\b0\d{2,3}[[:space:].-]?\d{6,8}\b|\+\d{1,3}[[:space:].-]?\d{6,12}\b)' \
  || echo "PASS"
```

Five categories combined into one regex:
1. "Novamira Hub" mentions (internal/future product name, never mentioned in this repo)
2. Personal / local paths (`/Users/`, `/home/<user>/`, `/Volumes/`)
3. Possible secrets (api keys, bearer tokens, Stripe sk_, AWS AKIA, JWT prefixes)
4. Private or reviewer-facing comments (`TODO`, `FIXME`, `XXX`, "reviewer"/"revisore", "remove before release") — per this project's convention, the "why" behind a change belongs in the commit message, never in a comment aimed at whoever reviews the diff.
5. Other private information that has no business shipping in a plugin: personal email addresses (anything not `@novamira.ai`/`@ovation.*`), phone numbers, real customer or site data pasted in for debugging.

Status: `PASS` if grep finds nothing; `FAIL — N findings` otherwise, with each hit shown as `<diff context> — <line>` so the user can locate it. A hit isn't automatically wrong (a `TODO` describing a real known limitation, or a third party's public support email quoted in a code comment, can be legitimate) — use judgment on whether it's a private note/detail that shouldn't ship or a genuine, intentional inclusion, and say which for each finding.

## Step 4 — Build verification

```bash
VERSION="$(grep -m1 'Version:' novamira.php | sed -E 's/.*Version:[[:space:]]*([^[:space:]]+).*/\1/')"
./build/build "$VERSION"
ZIP="/tmp/novamira-${VERSION}.zip"
unzip -Z1 "$ZIP" | sed 's|^novamira/||' \
  | grep -E '^(\.|bin/|build/|docs/|stubs/|tests/|src/|vendor/php-stubs/|vendor/bin/|phpunit\.xml$|release-info\.json$|CHANGELOG\.txt$|CLAUDE\.md$|GEMINI\.md$|AGENTS\.md$|Makefile$|mago\.toml$|composer\.(json|lock)$|package\.json$|bun\.lock$|tsconfig\.json$|novamira-visual/(src|stubs|packages|node_modules|docs)/|novamira-visual/(package\.json|tsconfig\.json|vite\.config\.ts|eslint\.config\.js|bun\.lock|wp-playground-mcp\.md|composer\.(json|lock))$)' \
  && STATUS=FAIL || STATUS=PASS
```

This mirrors `build/build-ignore`; a match means that file has to be excluded there before releasing. `FAIL — N internal files leaked` if grep matches; show the matched paths. `PASS` otherwise.

Note: `./build/build` runs `bun install` and rebuilds the Novamira Chat (and Visual, if present) assets, so this step takes longer than the other three and needs network access for the Bun install.

## Final report

```
| Check                | Status | Notes                                  |
|-----------------------|--------|----------------------------------------|
| mago format           | <…>    | <…>                                    |
| mago lint              | <…>    | <…>                                    |
| mago analyze           | <…>    | <…>                                    |
| phpunit                | <…>    | <…>                                    |
| internal-reveal scan   | <…>    | <…>                                    |
| build artifact         | <…>    | <…>                                    |

VERDICT: RELEASE GO
   — or —
VERDICT: RELEASE BLOCKED — <comma-separated FAIL list>
```

For each FAIL, list the offending items inline below the table (file paths, regex hits, test names). No detail sections, no full output verbatim — the user can re-run the underlying command if they want more.

## What this skill does NOT do

- Commit, push, tag, or upload anything.
- Modify code (mago fixes and failing tests are the user's call).
- Touch CHANGELOG.txt or release-info.json.
- Work on novamira-pro or novamira-visual as standalone releases.

## Related skills

- `changelog` — convention reference for entries.
- `bump-version` — bumps the version in `novamira.php`, `includes/compatibility.php`, `release-info.json` (indirectly), and `CHANGELOG.txt`.
- `release` — runs `bin/release`, the actual non-interactive release flow (which repeats mago checks, tests, and the build as its own steps).
