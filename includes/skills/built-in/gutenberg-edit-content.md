---
name: gutenberg-edit-content
description: Create or edit WordPress content in the native Gutenberg/block editor using Novamira Gutenberg abilities. Activate when the user asks to build, rebuild, migrate, or update a post, page, template, template part, or navigation using Gutenberg/native blocks.
---

# Editing Gutenberg Content

Use this playbook for native WordPress block editor work. Gutenberg static
blocks need the browser JavaScript serializer before queued content becomes
live, so the Novamira Block Editor Queue admin page is part of the workflow.

## Start Here

1. Call `novamira/gutenberg-get-finalizer-runtime`.
2. If `finalizer_runtime.online` is false, ask the user to open
   `finalizer_runtime.dashboard_url` in `wp-admin` and keep that page open
   while you work. Continue planning and reading content, but do not assume a
   queued static/native block batch can finalize until the runtime is online
   and able to process queued changes.
3. If `finalizer_runtime.online` is true, tell the user to keep the queue
   page open. If later batch responses show `online=false`, the page was
   closed, logged out, or lost its session; ask the user to reopen it.
4. Use `finalizer_runtime.sse_url` with `curl -N` for waiting. The stream
   sends short-lived status events and may ask you to reconnect before PHP
   execution limits apply. Use `finalizer_runtime.poll_url` with plain `curl`
   as the fallback. Do not repeatedly call MCP abilities just to wait for the
   page to open or for a batch to finish.

## Read Before Writing

- Use `novamira/gutenberg-get-content` for the target. It reads live
  `post_content`, not queued pending specs.
- If `pending_gutenberg_change` is present, inspect the named batch with
  `novamira/gutenberg-get-pending-batch` before editing. Do not stack another
  pending change on the same target unless the user confirms the old batch
  should be canceled.

## Choose the Write Path

- Use `novamira/gutenberg-write-content` only when every block is a registered
  `novamira/*` dynamic-only block. This writes live content directly.
- For core/static/native blocks, use the pending queue:
  1. Call `novamira/gutenberg-add-pending-change`. Omit `batch_id` for the
     first target, then reuse the returned `batch_id` for the rest of the
     same user-visible change.
  2. Call `novamira/gutenberg-enable-batch-finalization` after all changes
     are queued.
  3. If `finalizer_runtime.online` and `can_finalize_batch` are true, stream
     `finalizer_runtime.sse_url` with `curl -N` until the batch reaches
     `finalized`, `failed`, or `conflicted`.
  4. If the runtime is offline or becomes offline while polling, ask the user
     to reopen the generic Block Editor Queue page and keep it open. The
     returned `finalization_url` points to the same generic page.

## Completion

Queued Gutenberg changes are not live until the batch reports `finalized`.
Item status `prepared` only means the browser serializer has staged canonical
content for that item; it is not live content.
After finalization, re-read the edited target with
`novamira/gutenberg-get-content` and verify the saved block tree matches the
intended structure.
