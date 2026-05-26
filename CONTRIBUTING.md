# Contributing

## Before You Start

Every pull request must be linked to an open issue. If one doesn't exist yet, please open an issue first and describe what you want to change or fix.

**Wait for issue approval before writing code.** This avoids the situation where you put in effort on a PR that doesn't align with the project's direction and ends up not being merged.

## Process

1. Open an issue describing the bug or feature
2. Wait for a maintainer to acknowledge it and give the go-ahead
3. Fork the repo and create a branch from `main`
4. Make your changes
5. Ensure code passes all checks before submitting:
   ```sh
   make mago-format
   make mago-lint
   make mago-analyze
   ```
   These make targets verify that the installed Mago version matches the version locked in `composer.lock` before running Mago.
6. Open a pull request referencing the issue (e.g. `Closes #123`)
