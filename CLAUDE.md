## What This Is

Novamira gives an AI agent the same access to WordPress as the administrator who connects it, through the WordPress Abilities API + MCP Adapter: it runs PHP, reads and writes files and queries the database, so it can do *anything* WordPress can do: install plugins, modify themes, call external APIs, create custom functionality on the fly. This access is unrestricted by design, and that is why approvals and the AI change history exist. Only administrators can connect an AI or decide on approvals: keeping everyone else out is a real security boundary.

This is intentional. The abilities are unconstrained building blocks, and the plugin's value is that it turns a WordPress site into a fully programmable environment for AI. Do not add limits to the abilities themselves to make them safer: the safeguards sit around them, in approvals and the AI change history described below.

Since v2, AI approvals and the AI change history sit around that access (`includes/confirmations/`, `includes/journal/`). Before a call that changes the site, the AI rates it and a code check can raise the rating; at or above the level the administrator chose, the call waits for a person in Novamira → AI Activity. Administrators can also make any ability always ask (Abilities Hub) and list actions of their site to ask before (Also ask before). The change history records the AI's database changes with their previous values when it can, so the user can ask the AI to put things back with a new change, which is not an exact rollback, and emails sent while the AI works wait for a person. They help catch the mistakes of an AI working for the user; they are not a security boundary.

Requires WordPress 6.9+. The MCP Adapter is bundled as a Composer dependency (`wordpress/mcp-adapter`, at `vendor/wordpress/mcp-adapter/`).

## Code Quality

All code changes must pass these before committing:

```sh
make mago-format     # auto-format (print-width 120)
make mago-lint       # lint checks
make mago-analyze    # static analysis (PHP 8.0, includes WP stubs)
```

Use the make targets instead of running Mago directly. Ensure no issues or warnings of any kind when you commit. Also fix any previous unrelated issues you find.

NEVER modify release-info.json by hand unless explicitly instructed to do so, that is modified programmatically

Use Bun for JavaScript dependency and script commands in this repository. Do not use npm; for example, use `bun install` and `bun run build`.
