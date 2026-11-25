<p align="center">
  <a href="https://novamira.ai/">
    <img src="assets/banner.png" alt="Novamira" />
  </a>
</p>

<p align="center">
  <strong>Give your AI agents full access to your WordPress site. Nothing in between.</strong>
</p>

<p align="center">
  <a href="https://novamira.ai/">Website</a> ·
  <a href="https://novamira.ai/docs/">Documentation</a> ·
  <a href="https://novamira.ai/cli/">Novamira CLI</a> ·
  <a href="https://novamira.ai/video/">Videos</a> ·
  <a href="https://novamira.ai/download/">Download</a> ·
  <a href="https://discord.gg/novamira">Discord</a> ·
  <a href="https://www.facebook.com/groups/novamira">Facebook Community</a>
</p>

Novamira is an open-source WordPress plugin and MCP server that lets AI agents work directly inside WordPress. Agents can run PHP and WP-CLI commands, inspect the database, manage files, understand the active plugins and theme, and build working functionality against the real site. Ready for production since Novamira v2.

The connection is direct between your AI client and your WordPress installation. Novamira is not a hosted proxy and your requests do not pass through Novamira servers.

> [!WARNING]
> Approvals rely on your AI's judgement: use a capable AI model, and keep a backup.

## Videos

See Novamira in real projects, tutorials, and walkthroughs from the WordPress community.

**[Watch Novamira videos →](https://novamira.ai/video/)**

## What Novamira provides

- Full WordPress access through PHP execution, including `$wpdb`, loaded plugins, themes, and WordPress APIs
- WP-CLI commands with foreground and background execution
- Filesystem inspection and editing, plus a recoverable sandbox for new PHP files
- Native Block Editor workflows, media uploads, reusable skills, agent context, and design guidance
- OAuth and WordPress Application Password authentication
- Compatibility with Claude, Codex, Cursor, Gemini CLI, Antigravity, VS Code with GitHub Copilot, and other MCP clients
- [Novamira CLI](https://novamira.ai/cli/) for connecting terminal-based coding agents such as Claude Code, Codex CLI, and Gemini CLI
- Novamira Visual, an experimental browser workspace where you can watch an agent work in WordPress
- Keys, passwords and common API keys are replaced with `[redacted]` in what the agent receives, to avoid accidental leaks

## Requirements

- WordPress 6.9 or later
- PHP 8.0 or later
- A WordPress administrator account
- HTTPS for remote connections; local development environments are supported without HTTPS
- An MCP-compatible AI client, or a terminal-based coding agent using Novamira CLI

## Get started

1. [Download the release ZIP](https://novamira.ai/download/).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload the ZIP, and activate Novamira.
3. Open **Novamira → Configuration**, enable AI Abilities, and follow the instructions for your AI client and authentication method.

Use the release ZIP rather than GitHub's automatically generated source archive. The source archive does not include the bundled Composer dependencies required by the MCP server.

See the [quick start guide](https://novamira.ai/quickstart/) for the complete connection flow.

Using a terminal-based coding agent? [Novamira CLI](https://novamira.ai/cli/) provides a guided connection for Claude Code, Codex CLI, Gemini CLI, and other agents that can run shell commands.

## AI approvals

Before an action that can change the site, your AI rates how much it deserves a look, following a scale Novamira gives it. Novamira also reads the code and raises the rating of some actions it recognises, such as deleting content, installing plugins, WooCommerce refunds made through WooCommerce's standard functions, or sending data to an address not approved yet. The higher rating counts. At or above the level you choose (**Ask more**, **Balanced** or **Ask less**), the action waits under **Novamira → AI Activity** until a person approves or rejects it, for up to 24 hours.

In the **Abilities Hub** you can set any ability, other plugins' included, to always ask. Under **Novamira → AI Activity → Settings**, in **Also ask before**, you can list in your own words other actions of your site: your AI is told to ask before them, however it does them.

Approvals rely on your AI's judgement and on what Novamira recognises: they do not catch everything, and you can skip them. Approvals are designed to catch the mistakes of an AI working for you. They are not designed to stop an AI, or a person, that is trying to get around them. Use a capable AI model and keep a backup.

### AI change history

While it is on (**Novamira → Features**), Novamira records the database changes your AI makes, with the previous values when it can, for 30 days. To restore something, ask your AI: it reads the history and can make a new change that puts things back. It is not an exact rollback. Files, emails already sent, requests to other services and, if your site sells online, payments are not in the history. With the default settings, emails sent through WordPress while the AI works wait on the page until you send or discard them.

## Read mode

Read mode, turned on with [Novamira Pro](https://novamira.ai/pro/), lets an agent inspect a site with read abilities only: the abilities that change content, files and settings are removed. The **Novamira → Read Mode** page shows whether it is on and turns it off without needing Pro or a license; `wp novamira read-mode disable` does the same from WP-CLI. Read mode is not a security feature: it limits what Novamira offers an agent, not what an administrator or another plugin can do.

## Documentation

- [Documentation](https://novamira.ai/docs/)
- [Getting started](https://novamira.ai/docs/getting-started/)
- [Novamira CLI](https://novamira.ai/cli/)
- [Videos](https://novamira.ai/video/)
- [Security and best practices](https://novamira.ai/docs/security/)
- [Connection troubleshooting](https://novamira.ai/docs/connection-troubleshooting/)
- [Changelog](CHANGELOG.txt)

## Community

- [Discord](https://discord.gg/novamira)
- [Facebook Community](https://www.facebook.com/groups/novamira)
- [GitHub Discussions](https://github.com/use-novamira/novamira/discussions)

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request. Every contribution must start with an approved issue.

## License

[AGPL-3.0-or-later](https://www.gnu.org/licenses/agpl-3.0.html)
