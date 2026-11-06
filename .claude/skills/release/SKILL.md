---
name: release
description: Release a new version of the Novamira plugin with bin/release — the non-interactive step flow, the changelog entry, new-file checks, the manual commit/tag/push stop, and the rollout decision. Use whenever the user asks to cut, ship, publish, or finalize a release, or when a bin/release run stops and needs to be resumed.
---

# Releasing Novamira

Run the release as `bin/release VERSION --non-interactive`. It never waits for input: any step that needs a decision fails with the reason and the `RESUME_FROM="step name"` command to continue after fixing it. `bin/release --list-steps` lists the step names. The run first verifies that `HEAD` matches its upstream; update the branch yourself if it does not, because the release never changes the source tree with `git pull`.

## Before starting

Write the changelog entry first, at the top of `CHANGELOG.txt` with no version header or under an `Unreleased` header — the release assigns `vVERSION - date`, replacing `Unreleased` when it is there. Proofread it yourself; this mode does not spellcheck.

NEVER modify `release-info.json` by hand; it is modified programmatically — `bin/release-info.js` writes it, and `--list-steps` aside, no step expects you to open it.

## Steps that stop the run

- **"wordpress compatibilities"** fails whenever WordPress ships any release newer than `requirements.tested`, including a patch. Test the plugin against that version, then record it with `bin/release-info.js set-tested X.Y.Z`. Releasing without testing against it is the user's call to make, not yours.
- **"run tests"** runs `make test-unit`, the PHPUnit suite. It needs no WordPress install, host or network, so there is no environment to blame and no flag to skip it: a failure is the code, and the release stops until it passes. The MCP suite (`make test`) runs against a live environment and is deliberately not part of the release.
- **"check files"** fails when the zip ships files the previous release did not. Read the list: anything that does not belong in a plugin distributed to users goes in `build/build-ignore`. Re-run with `--accept-new-files` once the additions are meant to ship, and show the user the list when it is not obvious.
- **"commit release"** verifies rather than commits. The first run stops there; commit, tag and push exactly as instructed, then resume with `RESUME_FROM="commit release"`.
- **"verify release artifact"** checks that the reviewed zip is unchanged and that its tracked inputs match the release commit. If it fails, resume from `"build zip"`; never bypass it.
- **"create GitHub release"** compares Git tree IDs and waits up to one minute for the filtered public repository to contain the exact expected source, then pins the public tag to that commit. A mismatched or delayed sync fails rather than tagging stale source.

Resuming skips every earlier step, including "build zip". The script records the reviewed zip and its tracked inputs in `/tmp` and rechecks them before every publishing action, so changed code, a changed zip, or a new shell host requires resuming from `"build zip"`.

## Rollout — always the user's call

The run then stops with exit code 10: everything is uploaded, but the version is not live. **Ask the user how to roll it out** — instantly, or gradually over 6, 12, 24, 72 or 168 hours — and never choose for them.

Then carry out their answer:

```sh
bin/release VERSION --finalize --rollout-hours N
bin/release VERSION --finalize --instant
```
