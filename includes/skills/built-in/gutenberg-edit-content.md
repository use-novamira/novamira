---
name: gutenberg-edit-content
description: Create or edit WordPress content in the native Gutenberg/block editor using Novamira Gutenberg abilities. Activate when the user asks to build, rebuild, migrate, or update a post, page, template, template part, or navigation using Gutenberg/native blocks.
---

# Editing Gutenberg Content

Use this playbook for native WordPress block editor work. Gutenberg static
blocks need the browser JavaScript serializer before queued content becomes
live, so a Novamira browser finalizer runtime must be online. In Dashboard
Agent that runtime is embedded in the Novamira Chat page; external MCP
clients use the standalone Block Editor Queue admin page.

## Start Here

1. Call `novamira/gutenberg-get-finalizer-runtime`.
2. If you are running inside Novamira Chat and `finalizer_runtime.online` is
   true, do not ask the user to open a separate finalization page. The embedded
   runtime should process ready batches while this dashboard remains open.
3. If you are running inside Novamira Chat and `finalizer_runtime.online` is
   false, ask the user to reload Novamira Chat. Continue planning and reading
   content, but do not assume a queued static/native block batch can finalize
   until the runtime is online and able to process queued changes.
4. If you are running as an external MCP client and `finalizer_runtime.online`
   is false, ask the user to open `finalizer_runtime.dashboard_url` in
   `wp-admin` and keep that page open while you work.
5. If later batch responses show `online=false`, the browser runtime was
   closed, logged out, or lost its session; ask the user to reopen or reload
   the relevant page.
6. Use `finalizer_runtime.sse_url` with `curl -N` for waiting. The stream
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

## Compose With Registered Blocks

Build content from registered blocks — core *and* third-party — supplied as
`{name, attributes, innerBlocks}`. The finalizer serializes each block with its
own editor JavaScript, so never hand-write a block's HTML.

- Core blocks: `core/heading` (set `level`), `core/paragraph`, `core/list` +
  `core/list-item`, `core/image`, `core/quote`, `core/buttons` + `core/button`,
  `core/table`, `core/code`, `core/separator`. Express layout and nesting with
  `core/group` / `core/columns` + `core/column` and `innerBlocks`, not HTML.
- Third-party blocks (WooCommerce, Kadence, ACF, etc.): if a registered block
  exists for what you are building, use it — pass its `name` and `attributes`
  instead of reproducing its output. To discover the available block names and
  their attributes, query the registry, e.g. via `execute-php`:
  `WP_Block_Type_Registry::get_instance()->get_all_registered()`.
- Builder-owned blocks (`divi/*`, and any namespace a site adds through
  the `novamira_gutenberg_builder_block_namespaces` filter) are
  rejected by both write paths: those builders register their blocks only
  inside their own runtime, not in the block editor, so the queue cannot
  serialize them. Write that content with the builder's dedicated abilities;
  if the site has none, tell the user instead of falling back to raw HTML.
- Use `core/html` only for a small fragment with no registered-block
  equivalent, and keep it minimal. Never wrap whole sections or the entire post
  in `core/html` or a classic/freeform block: raw-HTML blocks are not visually
  editable and defeat native editing.

## Choose the Write Path

- Use `novamira/gutenberg-write-content` only when every block is a registered
  `novamira/*` dynamic-only block. This writes live content directly.
- For static/native blocks, use the pending queue:
  1. Call `novamira/gutenberg-add-pending-change`. Omit `batch_id` for the
     first target, then reuse the returned `batch_id` for the rest of the
     same user-visible change.
  2. Call `novamira/gutenberg-enable-batch-finalization` after all changes
     are queued.
  3. If `finalizer_runtime.online` and `can_finalize_batch` are true, stream
     `finalizer_runtime.sse_url` with `curl -N` until the batch reaches
     `finalized`, `failed`, or `conflicted`.
  4. If the runtime is offline or becomes offline while polling, ask the user
     to reload Novamira Chat when running inside Novamira Chat. External
     MCP clients should ask the user to reopen the returned
     `finalization_url`.

## Completion

Queued Gutenberg changes are not live until the batch reports `finalized`.
Item status `prepared` only means the browser serializer has staged canonical
content for that item; it is not live content.
After finalization, re-read the edited target with
`novamira/gutenberg-get-content` and verify the saved block tree matches the
intended structure.
