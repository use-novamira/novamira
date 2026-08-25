<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Standalone-testable: allow require under PHP CLI without a WordPress bootstrap.
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit();
}

/**
 * Agent-skill destinations understood by the Novamira CLI installers. Keep these
 * separate from MCP clients: a CLI agent key
 * selects where the Novamira skill is installed, while an MCP client key selects
 * a connection-file format.
 *
 * `project` entries cannot use the installers' normal --global path. They are
 * still valid destinations, but their setup command must run from the agent's
 * project directory.
 *
 * @return array<string, array{label: string, scope: 'global'|'project'}>
 */
// This is one flat external compatibility manifest; splitting it into arbitrary alphabetical
// functions would hide duplicates and make updates against the pinned installer harder to review.
// @mago-expect lint:halstead
function novamira_cli_agents(): array
{
    return [
        'adal' => ['label' => 'AdaL', 'scope' => 'global'],
        'aider-desk' => ['label' => 'AiderDesk', 'scope' => 'global'],
        'amp' => ['label' => 'Amp', 'scope' => 'global'],
        'antigravity' => ['label' => 'Antigravity', 'scope' => 'global'],
        'antigravity-cli' => ['label' => 'Antigravity CLI', 'scope' => 'global'],
        'astrbot' => ['label' => 'AstrBot', 'scope' => 'global'],
        'autohand-code' => ['label' => 'Autohand Code CLI', 'scope' => 'global'],
        'augment' => ['label' => 'Augment', 'scope' => 'global'],
        'bob' => ['label' => 'IBM Bob', 'scope' => 'global'],
        'claude-code' => ['label' => 'Claude Code', 'scope' => 'global'],
        'cline' => ['label' => 'Cline', 'scope' => 'global'],
        'codearts-agent' => ['label' => 'CodeArts Agent', 'scope' => 'global'],
        'codebuddy' => ['label' => 'CodeBuddy', 'scope' => 'global'],
        'codemaker' => ['label' => 'Codemaker', 'scope' => 'global'],
        'codestudio' => ['label' => 'Code Studio', 'scope' => 'global'],
        'codex' => ['label' => 'Codex CLI', 'scope' => 'global'],
        'command-code' => ['label' => 'Command Code', 'scope' => 'global'],
        'continue' => ['label' => 'Continue', 'scope' => 'global'],
        'cortex' => ['label' => 'Cortex Code', 'scope' => 'global'],
        'crush' => ['label' => 'Crush', 'scope' => 'global'],
        'cursor' => ['label' => 'Cursor', 'scope' => 'global'],
        'deepagents' => ['label' => 'Deep Agents', 'scope' => 'global'],
        'devin' => ['label' => 'Devin for Terminal', 'scope' => 'global'],
        'dexto' => ['label' => 'Dexto', 'scope' => 'global'],
        'droid' => ['label' => 'Droid', 'scope' => 'global'],
        'eve' => ['label' => 'Eve', 'scope' => 'project'],
        'firebender' => ['label' => 'Firebender', 'scope' => 'global'],
        'forgecode' => ['label' => 'ForgeCode', 'scope' => 'global'],
        'gemini-cli' => ['label' => 'Gemini CLI', 'scope' => 'global'],
        'github-copilot' => ['label' => 'GitHub Copilot', 'scope' => 'global'],
        'goose' => ['label' => 'Goose', 'scope' => 'global'],
        'hermes-agent' => ['label' => 'Hermes Agent', 'scope' => 'global'],
        'iflow-cli' => ['label' => 'iFlow CLI', 'scope' => 'global'],
        'inference-sh' => ['label' => 'inference.sh', 'scope' => 'global'],
        'jazz' => ['label' => 'Jazz', 'scope' => 'global'],
        'junie' => ['label' => 'Junie', 'scope' => 'global'],
        'kilo' => ['label' => 'Kilo Code', 'scope' => 'global'],
        'kimi-code-cli' => ['label' => 'Kimi Code CLI', 'scope' => 'global'],
        'kiro-cli' => ['label' => 'Kiro CLI', 'scope' => 'global'],
        'kode' => ['label' => 'Kode', 'scope' => 'global'],
        'lingma' => ['label' => 'Lingma', 'scope' => 'global'],
        'loaf' => ['label' => 'Loaf', 'scope' => 'global'],
        'mcpjam' => ['label' => 'MCPJam', 'scope' => 'global'],
        'mistral-vibe' => ['label' => 'Mistral Vibe', 'scope' => 'global'],
        'moxby' => ['label' => 'Moxby', 'scope' => 'global'],
        'mux' => ['label' => 'Mux', 'scope' => 'global'],
        'neovate' => ['label' => 'Neovate', 'scope' => 'global'],
        'ona' => ['label' => 'Ona', 'scope' => 'global'],
        'openclaw' => ['label' => 'OpenClaw', 'scope' => 'global'],
        'opencode' => ['label' => 'OpenCode', 'scope' => 'global'],
        'openhands' => ['label' => 'OpenHands', 'scope' => 'global'],
        'pi' => ['label' => 'Pi', 'scope' => 'global'],
        'pochi' => ['label' => 'Pochi', 'scope' => 'global'],
        'promptscript' => ['label' => 'PromptScript', 'scope' => 'project'],
        'qoder' => ['label' => 'Qoder', 'scope' => 'global'],
        'qoder-cn' => ['label' => 'Qoder CN', 'scope' => 'global'],
        'qwen-code' => ['label' => 'Qwen Code', 'scope' => 'global'],
        'reasonix' => ['label' => 'Reasonix', 'scope' => 'global'],
        'replit' => ['label' => 'Replit', 'scope' => 'global'],
        'roo' => ['label' => 'Roo Code', 'scope' => 'global'],
        'rovodev' => ['label' => 'Rovo Dev', 'scope' => 'global'],
        'tabnine-cli' => ['label' => 'Tabnine CLI', 'scope' => 'global'],
        'terramind' => ['label' => 'Terramind', 'scope' => 'global'],
        'tinycloud' => ['label' => 'Tinycloud', 'scope' => 'global'],
        'trae' => ['label' => 'Trae', 'scope' => 'global'],
        'trae-cn' => ['label' => 'Trae CN', 'scope' => 'global'],
        'warp' => ['label' => 'Warp', 'scope' => 'global'],
        'windsurf' => ['label' => 'Windsurf', 'scope' => 'global'],
        'zcode' => ['label' => 'ZCode', 'scope' => 'global'],
        'zed' => ['label' => 'Zed', 'scope' => 'global'],
        'zencoder' => ['label' => 'Zencoder', 'scope' => 'global'],
        'zenflow' => ['label' => 'Zenflow', 'scope' => 'global'],
    ];
}

/**
 * Translate a visible Configuration-page client key to its skills installer key.
 * Most names match; these aliases keep product distinctions visible without
 * leaking incompatible implementation slugs into the UI.
 */
function novamira_cli_agent_for_client(string $client): ?string
{
    $agent = match ($client) {
        'codex-cli' => 'codex',
        'kilo-code' => 'kilo',
        'roo-code' => 'roo',
        default => $client,
    };

    return array_key_exists($agent, novamira_cli_agents()) ? $agent : null;
}

/**
 * Quote one value for a POSIX shell command displayed to the user.
 */
function novamira_cli_shell_quote(string $value): string
{
    return "'" . str_replace(search: "'", replace: "'\\''", subject: $value) . "'";
}

/**
 * Whether a CLI site URL uses plain HTTP on a loopback host, which the CLI
 * permits without an insecure-transport override.
 */
function novamira_cli_uses_loopback_http(string $site_url): bool
{
    if (strtolower((string) parse_url($site_url, PHP_URL_SCHEME)) !== 'http') {
        return false;
    }
    $host = strtolower(trim((string) parse_url($site_url, PHP_URL_HOST), characters: '[]'));
    return $host === 'localhost' || $host === '::1' || preg_match('/^127(?:\.\d{1,3}){3}$/', $host) === 1;
}

/**
 * The CLI requires HTTPS except on loopback or when WordPress explicitly marks
 * a development site as local.
 */
function novamira_cli_transport_allowed(string $site_url, string $environment): bool
{
    $scheme = strtolower((string) parse_url($site_url, PHP_URL_SCHEME));
    return (
        $scheme === 'https'
        || novamira_cli_uses_loopback_http($site_url)
        || $scheme === 'http'
        && $environment === 'local'
    );
}

/**
 * A non-loopback local HTTP site needs the CLI's explicit per-command opt-in.
 */
function novamira_cli_needs_insecure_http_override(string $site_url, string $environment): bool
{
    return (
        novamira_cli_transport_allowed($site_url, $environment)
        && strtolower((string) parse_url($site_url, PHP_URL_SCHEME)) === 'http'
        && !novamira_cli_uses_loopback_http($site_url)
    );
}

/**
 * macOS/Linux installer command for one agent destination.
 */
function novamira_cli_unix_install_command(string $agent, string $scope): string
{
    if ($scope === 'project') {
        return implode("\n", [
            'npm install --global --ignore-scripts @novamira/cli',
            'DISABLE_TELEMETRY=1 npm_config_ignore_scripts=true npx --yes skills@1.5.18 add "$(npm root --global)/@novamira/cli"'
                . ' --skill novamira --agent '
                . novamira_cli_shell_quote($agent)
                . ' --yes',
            'novamira doctor --offline',
        ]);
    }

    return (
        'curl -fsSL https://raw.githubusercontent.com/use-novamira/novamira-cli/main/install.sh'
        . ' | env NOVAMIRA_AGENT='
        . novamira_cli_shell_quote($agent)
        . ' sh'
    );
}

/**
 * Windows PowerShell installer command for one agent destination.
 */
function novamira_cli_windows_install_command(string $agent, string $scope): string
{
    $quoted_agent = "'" . str_replace(search: "'", replace: "''", subject: $agent) . "'";
    if ($scope === 'project') {
        return implode("\n", [
            'npm install --global --ignore-scripts @novamira/cli',
            '$skillSource = Join-Path (npm root --global) \'@novamira/cli\'',
            '$env:DISABLE_TELEMETRY = \'1\'; $env:npm_config_ignore_scripts = \'true\'',
            'npx --yes skills@1.5.18 add $skillSource --skill novamira --agent ' . $quoted_agent . ' --yes',
            'novamira doctor --offline',
        ]);
    }

    return (
        '$env:NOVAMIRA_AGENT = '
        . $quoted_agent
        . '; irm https://raw.githubusercontent.com/use-novamira/novamira-cli/main/install.ps1 | iex'
    );
}

/**
 * Login command after installing the CLI. The flags mirror the two local-site
 * exceptions already explained elsewhere on the Configuration page.
 *
 * @param array<string, string> $environment
 */
function novamira_cli_login_command(string $site_url, array $environment = []): string
{
    $assignments = [];
    foreach ($environment as $key => $value) {
        $assignments[] = $key . '=' . novamira_cli_shell_quote($value);
    }
    $prefix = $assignments !== [] ? implode(' ', $assignments) . ' ' : '';
    return $prefix . 'novamira auth login ' . novamira_cli_shell_quote($site_url);
}

/**
 * PowerShell form of the CLI login command.
 *
 * @param array<string, string> $environment
 */
function novamira_cli_windows_login_command(string $site_url, array $environment = []): string
{
    $commands = [];
    foreach ($environment as $key => $value) {
        $quoted_value = "'" . str_replace(search: "'", replace: "''", subject: $value) . "'";
        $commands[] = '$env:' . $key . ' = ' . $quoted_value;
    }
    $quoted_url = "'" . str_replace(search: "'", replace: "''", subject: $site_url) . "'";
    $commands[] = 'novamira auth login ' . $quoted_url;
    return implode('; ', $commands);
}

/**
 * True when an IPv4/IPv6 literal is in a private, loopback, link-local, or reserved range.
 */
function novamira_ip_is_private_or_loopback(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        // Fails validation (=== false) exactly when the address is private or reserved.
        return (
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            === false
        );
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $lower = strtolower($ip);
        return (
            $lower === '::1'
            || str_starts_with($lower, 'fe80:')
            || str_starts_with($lower, 'fc')
            || str_starts_with($lower, 'fd')
        );
    }
    return false;
}

/**
 * True when a host is only reachable from the local machine and never from the public
 * internet: single-label hosts, private/loopback IP literals, and local-dev suffixes.
 * Expects a bare host without a port.
 */
function novamira_host_is_local_only(string $host): bool
{
    $host = strtolower(trim($host));
    $host = trim($host, characters: '[]');
    if ($host === '') {
        return false;
    }

    // IP literal → private/loopback ranges are local-only.
    $ip = filter_var($host, FILTER_VALIDATE_IP);
    if ($ip !== false) {
        return novamira_ip_is_private_or_loopback($ip);
    }

    // Single-label host (no dot), e.g. "localhost" or "wordpress".
    if (!str_contains($host, '.')) {
        return true;
    }

    /** @var array<int, string> $suffixes */
    $suffixes = apply_filters('novamira_local_only_host_patterns', [
        '.local',
        '.test',
        '.localhost',
        '.ddev.site',
        '.lndo.site',
    ]);
    foreach ($suffixes as $suffix) {
        if ($suffix !== '' && str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

/**
 * True when this site's host cannot be reached from Anthropic's cloud, so a native remote
 * connector will not work and the OAuth flow must run through a local stdio bridge.
 */
function novamira_host_unreachable_from_cloud(): bool
{
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    return novamira_host_is_local_only($host);
}

/**
 * True when it is safe to run the OAuth flow: the site is served over HTTPS, or WordPress reports a
 * local environment. On a plain-HTTP site the authorization code and the bearer tokens travel in
 * cleartext (RFC 6749/6750 require TLS), and "not reachable from the public internet" is not enough
 * on its own: a private-IP or *.local/*.test host still shares a LAN wire another device can sniff.
 * So HTTP is allowed only when wp_get_environment_type() is 'local' — the same transport policy
 * wp_is_application_passwords_supported() applies. Keyed off the canonical home_url() scheme, not
 * is_ssl(), so a TLS-terminating reverse proxy (https to the public, http to PHP) is not misjudged.
 */
function novamira_oauth_transport_allowed(): bool
{
    if (str_starts_with(strtolower(home_url()), 'https://')) {
        return true;
    }
    return wp_get_environment_type() === 'local';
}

/**
 * Build the Claude "add custom connector" prefill link. Opens the dialog with the name and
 * URL pre-filled; the user still reviews and confirms. No secret is embedded.
 */
function novamira_build_connector_install_link(string $mcp_url, string $connector_name): string
{
    return (
        'https://claude.ai/customize/connectors?modal=add-custom-connector'
        . '&connectorName='
        . rawurlencode($connector_name)
        . '&connectorUrl='
        . rawurlencode($mcp_url)
    );
}

/**
 * Human-readable connector name shown in Claude. Empty site name falls back to "Novamira".
 */
function novamira_build_connector_display_name(string $site_name): string
{
    $site_name = trim($site_name);
    return $site_name !== '' ? 'Novamira - ' . $site_name : 'Novamira';
}

/**
 * npx mcp-remote stdio bridge server object. The bridge runs the OAuth browser flow on behalf
 * of clients that do not perform it natively. $env carries the self-signed TLS bypass on local
 * sites and is empty on public ones.
 *
 * @param array<string, string> $env
 * @return array<string, mixed>
 */
function novamira_oauth_bridge_server(string $mcp_url, array $env): array
{
    $server = ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $mcp_url]];
    if ($env !== []) {
        $server['env'] = $env;
    }
    return $server;
}

/**
 * Wrap a per-client server object in its JSON envelope (mcpServers or servers).
 *
 * @param array<string, mixed> $server
 */
function novamira_oauth_json(string $wrapper, string $mcp_name, array $server): string
{
    return (string) json_encode([$wrapper => [$mcp_name => $server]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * Build a plain code-snippet client entry (no shell, no connector button). The optional note is
 * extra HTML rendered under the snippet (e.g. a CLI alternative); empty means none.
 *
 * @param array<string, string> $paths
 * @return array<string, mixed>
 */
function novamira_oauth_code_entry(string $code, string $hint, array $paths, string $note = ''): array
{
    return ['kind' => 'code', 'code' => $code, 'hint' => $hint, 'paths' => $paths, 'isShell' => false, 'note' => $note];
}

/**
 * "Add to <code>file</code>." hint, shared by the client config entries.
 */
function novamira_oauth_add_to(string $file): string
{
    /* translators: %s: config file name wrapped in <code> tags */
    return sprintf(__('Add to %s.', domain: 'novamira'), '<code>' . $file . '</code>');
}

/**
 * Codex config.toml snippet for the mcp-remote bridge (stdio launch, optional env block).
 *
 * @param array<string, string> $env
 */
function novamira_oauth_bridge_codex(string $mcp_name, string $mcp_url, array $env): string
{
    $tq = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    $lines = [
        '[mcp_servers.' . $mcp_name . ']',
        'command = "npx"',
        'args = ["-y", "mcp-remote", ' . $tq($mcp_url) . ']',
    ];
    if ($env !== []) {
        $lines[] = '';
        $lines[] = '[mcp_servers.' . $mcp_name . '.env]';
        foreach ($env as $key => $value) {
            $lines[] = $key . ' = ' . $tq($value);
        }
    }
    return implode("\n", $lines);
}

/**
 * `claude mcp add` command for the mcp-remote bridge (stdio launch, optional --env flags).
 *
 * @param array<string, string> $env
 */
function novamira_oauth_bridge_claude_code(string $mcp_name, string $mcp_url, array $env): string
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    // Claude Code's --env option is variadic: keep the server name before the first -e or the
    // parser treats the name itself as another KEY=value environment entry.
    $parts = ['claude mcp add', '--scope user', $sq($mcp_name)];
    foreach ($env as $key => $value) {
        $parts[] = '-e ' . $key . '=' . $sq($value);
    }
    $parts[] = '-- npx -y mcp-remote ' . $sq($mcp_url);
    return implode(" \\\n  ", $parts);
}

/**
 * User-scoped Claude Code command for a native remote HTTP server.
 */
function novamira_oauth_native_claude_code(string $mcp_name, string $mcp_url): string
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    return 'claude mcp add --transport http --scope user ' . $sq($mcp_name) . ' ' . $sq($mcp_url);
}

/**
 * Steps for ChatGPT's developer-mode plugin: turn on developer mode, create a plugin, paste the
 * OAuth server URL. ChatGPT reaches the server from OpenAI's cloud, so this is only offered on a
 * publicly reachable site (see novamira_build_oauth_configs()).
 *
 * @return list<array<string, string>>
 */
function novamira_oauth_chatgpt_steps(string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Enable developer mode', domain: 'novamira'),
            'body' => __(
                'In ChatGPT, open Settings, go to Security and login, and turn on Developer mode. This is the only way to add plugins that OpenAI has not reviewed, and yours will always be one of those: it connects directly to your own site, so it can never be published as a reviewed plugin. The warning ChatGPT shows is expected.',
                domain: 'novamira',
            ),
        ],
        [
            'title' => __('Create a plugin', domain: 'novamira'),
            'body' => __(
                'In Settings, open Plugins and press the button in the top right to create a new plugin. Give it this name, or one you’ll recognize with "Novamira" in it:',
                domain: 'novamira',
            ),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'novamira'),
            'body' => __(
                'Paste the URL below as the Server URL, keep Authentication set to OAuth, tick "I understand and want to continue", and press Create. Then sign in when the browser opens.',
                domain: 'novamira',
            ),
            'copy' => $mcp_url,
        ],
    ];
}

/**
 * A message-only client entry: no config, just an explanation. Used for a cloud client on a local
 * site, where the client's servers cannot reach the site so no working config exists.
 *
 * @return array<string, string>
 */
function novamira_oauth_cloud_only_notice(string $client_label): array
{
    return [
        'kind' => 'notice',
        'message' => sprintf(
            /* translators: %s: the AI client name, e.g. ChatGPT */
            __(
                '%s connects to your site from its own servers, so it can only reach a site that is available over the public internet, not one that runs only on your local machine.',
                domain: 'novamira',
            ),
            $client_label,
        ),
    ];
}

/**
 * OAuth per-client connection configs, mirroring the app-password client list.
 *
 * On a publicly reachable site each client gets its native remote-MCP form (a URL the client
 * connects to and runs OAuth against itself), except a tail of clients whose native OAuth flow
 * is not verified, which fall back to the mcp-remote stdio bridge. On a local site every client
 * uses the bridge (which also carries the self-signed TLS bypass). Browser-only cloud clients
 * receive an explanatory notice because they cannot reach a local URL.
 *
 * ChatGPT is cloud-only like Claude.ai but always kept in the list: publicly it gets the
 * developer-mode plugin steps, locally a notice explaining it needs a public site.
 *
 * @return array<string, array<string, mixed>>
 */
function novamira_build_oauth_configs(string $mcp_url, string $mcp_name): array
{
    // The TLS-verification bypass rides along only for a self-signed local certificate, the same
    // condition the app-password flow uses; a plain-HTTP local site or a trusted cert needs no flag.
    $env = novamira_likely_self_signed_https() ? ['NODE_TLS_REJECT_UNAUTHORIZED' => '0'] : [];
    if (novamira_host_unreachable_from_cloud()) {
        return (
            novamira_build_oauth_bridge_configs($mcp_url, $mcp_name, $env)
            + [
                'claude-ai' => novamira_oauth_cloud_only_notice('Claude.ai'),
                'chatgpt' => novamira_oauth_cloud_only_notice('ChatGPT'),
            ]
        );
    }
    return (
        novamira_build_oauth_public_configs($mcp_url, $mcp_name)
        + [
            'chatgpt' => [
                'kind' => 'code',
                'code' => '',
                'hint' => '',
                'paths' => [],
                'isShell' => false,
                'steps' => novamira_oauth_chatgpt_steps($mcp_name, $mcp_url),
            ],
        ]
    );
}

/**
 * Public site: native form per client, with the mcp-remote bridge as the fallback for the tail
 * of clients whose native OAuth flow is not verified. A public host has a trusted certificate,
 * so the bridge needs no TLS bypass.
 *
 * @return array<string, array<string, mixed>>
 */
function novamira_build_oauth_public_configs(string $mcp_url, string $mcp_name): array
{
    $bridge = novamira_build_oauth_bridge_configs($mcp_url, $mcp_name, []);
    $tail = ['cline', 'roo-code', 'amazon-q', 'zed', 'kilo-code', 'opencode'];

    return array_merge(
        array_intersect_key($bridge, array_flip($tail)),
        novamira_build_oauth_native_configs($mcp_url, $mcp_name),
    );
}

/**
 * Manual walkthrough for the Claude connector clients, which add remote MCP servers from the
 * Connectors UI rather than a config file. The server name keeps the placeholder that the page
 * script swaps for the live name.
 *
 * @return list<array<string, string>>
 */
function novamira_oauth_connector_steps(string $app_label, string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Open Connectors', domain: 'novamira'),
            /* translators: %s: the client name, e.g. Claude Desktop */
            'body' => sprintf(__('In %s, open Settings and go to Connectors.', domain: 'novamira'), $app_label),
        ],
        [
            'title' => __('Add a custom connector', domain: 'novamira'),
            'body' => __(
                'Click "Add custom connector" and give it this name, or one you’ll recognize with "Novamira" in it:',
                domain: 'novamira',
            ),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'novamira'),
            'body' => __(
                'Paste the URL below and save. Leave the OAuth Client ID and Secret (under Advanced settings) empty, then sign in when the browser opens.',
                domain: 'novamira',
            ),
            'copy' => $mcp_url,
        ],
    ];
}

/**
 * Codex CLI command for a remote HTTP MCP server. Codex stores it in the user-level config.
 */
function novamira_codex_http_add_command(string $mcp_name, string $mcp_url): string
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    return 'codex mcp add ' . $sq($mcp_name) . ' --url ' . $sq($mcp_url);
}

/**
 * Codex CLI command that launches a local stdio MCP server with optional environment variables.
 *
 * @param array<string, string> $env
 */
function novamira_codex_stdio_add_command(string $mcp_name, array $env, string $command): string
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    $parts = ['codex mcp add ' . $sq($mcp_name)];
    foreach ($env as $key => $value) {
        $parts[] = '--env ' . $key . '=' . $sq($value);
    }
    $parts[] = '-- ' . $command;
    return implode(" \\" . PHP_EOL . '  ', $parts);
}

/**
 * Codex CLI command for the local OAuth bridge.
 *
 * @param array<string, string> $env
 */
function novamira_oauth_codex_bridge_command(string $mcp_name, string $mcp_url, array $env): string
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    return novamira_codex_stdio_add_command($mcp_name, $env, 'npx -y mcp-remote ' . $sq($mcp_url));
}

/**
 * Terminal-first OAuth setup for a native Codex HTTP connection.
 *
 * @return list<array<string, string>>
 */
function novamira_oauth_codex_cli_steps(string $mcp_name, string $mcp_url): array
{
    $sq = static fn(string $v): string => "'" . str_replace(search: "'", replace: "'\\''", subject: $v) . "'";
    return [
        [
            'title' => __('Register the server globally', domain: 'novamira'),
            'body' => __(
                'Run this in your terminal. Codex saves MCP servers in your user configuration, so the server is available in every project.',
                domain: 'novamira',
            ),
            'code' => novamira_codex_http_add_command($mcp_name, $mcp_url),
        ],
        [
            'title' => __('Sign in with OAuth', domain: 'novamira'),
            'body' => __('Run this command and approve the authorization request in your browser.', domain: 'novamira'),
            'code' => 'codex mcp login ' . $sq($mcp_name),
        ],
    ];
}

/**
 * In-app setup for Codex inside ChatGPT Desktop.
 *
 * @return list<array<string, string>>
 */
function novamira_oauth_codex_desktop_steps(string $mcp_name, string $mcp_url): array
{
    return [
        [
            'title' => __('Open MCP servers', domain: 'novamira'),
            'body' => __('In ChatGPT Desktop, open Settings and select MCP servers.', domain: 'novamira'),
        ],
        [
            'title' => __('Add a server name', domain: 'novamira'),
            'body' => __('Select Add server, choose Streamable HTTP, and use this name:', domain: 'novamira'),
            'copy' => $mcp_name,
        ],
        [
            'title' => __('Enter the server URL', domain: 'novamira'),
            'body' => __('Paste this URL and save the server:', domain: 'novamira'),
            'copy' => $mcp_url,
        ],
        [
            'title' => __('Authenticate', domain: 'novamira'),
            'body' => __(
                'Select Authenticate for the new server and approve the authorization request in your browser. Restart ChatGPT Desktop if prompted.',
                domain: 'novamira',
            ),
        ],
    ];
}

/**
 * Native remote configs for clients that run the OAuth flow themselves. Claude Desktop and
 * Claude.ai share the custom-connector prefill (a button, not a snippet). On a local site,
 * Claude.ai gets a notice explaining that its cloud servers cannot reach the site.
 *
 * @return array<string, array<string, mixed>>
 */
function novamira_build_oauth_native_configs(string $mcp_url, string $mcp_name): array
{
    $connector = novamira_build_connector_install_link(
        $mcp_url,
        novamira_build_connector_display_name(get_bloginfo('name')),
    );
    $deeplink =
        'cursor://anysphere.cursor-deeplink/mcp/install?name='
        . $mcp_name
        . '&config='
        . base64_encode((string) json_encode(['url' => $mcp_url]));

    $connector_entry = static fn(string $hint): array => [
        'kind' => 'connector',
        'code' => '',
        'connector' => $connector,
        'hint' => $hint,
        'paths' => [],
        'isShell' => false,
    ];
    $antigravity_entry = novamira_oauth_code_entry(
        novamira_oauth_json('mcpServers', $mcp_name, ['serverUrl' => $mcp_url]),
        novamira_oauth_add_to('mcp_config.json'),
        [
            __('Global (macOS / Linux)', domain: 'novamira') => '~/.gemini/config/mcp_config.json',
            __('Global (Windows)', domain: 'novamira') => '%USERPROFILE%\\.gemini\\config\\mcp_config.json',
            __('Workspace', domain: 'novamira') => '.agents/mcp_config.json',
        ],
    );

    return [
        'claude-code' => [
            'kind' => 'code',
            'code' => novamira_oauth_native_claude_code($mcp_name, $mcp_url),
            'hint' => __(
                'Run in your terminal to make this server available in all your projects, then sign in when your browser opens.',
                domain: 'novamira',
            ),
            'paths' => [],
            'isShell' => true,
        ],
        // Claude Desktop adds remote servers from its own Connectors UI. The one-click button goes
        // through claude.ai (account level) and only reaches Desktop after a sync, so the in-app
        // walkthrough is the primary here rather than that button.
        'claude-desktop' => [
            'kind' => 'code',
            'code' => '',
            'hint' => '',
            'paths' => [],
            'isShell' => false,
            'steps' => novamira_oauth_connector_steps('Claude Desktop', $mcp_name, $mcp_url),
        ],
        'claude-ai' => array_merge(
            $connector_entry(__(
                'Add it as a custom connector on claude.ai, then sign in. Works in the browser and in Claude Desktop.',
                domain: 'novamira',
            )),
            ['steps' => novamira_oauth_connector_steps('claude.ai', $mcp_name, $mcp_url)],
        ),
        'codex-app' => [
            'kind' => 'code',
            'code' => '',
            'hint' => '',
            'paths' => [],
            'isShell' => false,
            'steps' => novamira_oauth_codex_desktop_steps($mcp_name, $mcp_url),
        ],
        'codex-cli' => [
            'kind' => 'code',
            'code' => '',
            'hint' => '',
            'paths' => [],
            'isShell' => true,
            'steps' => novamira_oauth_codex_cli_steps($mcp_name, $mcp_url),
        ],
        'antigravity' => $antigravity_entry,
        'antigravity-cli' => $antigravity_entry,
        'cursor' => array_merge(
            novamira_oauth_code_entry(
                novamira_oauth_json('mcpServers', $mcp_name, ['url' => $mcp_url]),
                sprintf(__('Use the one-click button, or add to %s.', domain: 'novamira'), '<code>mcp.json</code>'),
                [
                    __('Global', domain: 'novamira') => '~/.cursor/mcp.json',
                    __('Project', domain: 'novamira') => '.cursor/mcp.json',
                ],
            ),
            ['deeplink' => $deeplink],
        ),
        'vscode' => novamira_oauth_code_entry(
            novamira_oauth_json('servers', $mcp_name, ['type' => 'http', 'url' => $mcp_url]),
            novamira_oauth_add_to('mcp.json'),
            [
                __('Workspace', domain: 'novamira') => '.vscode/mcp.json',
                __('User', domain: 'novamira') => __(
                    'Run: MCP: Open User Configuration (command palette)',
                    domain: 'novamira',
                ),
            ],
        ),
        'github-copilot' => novamira_oauth_code_entry(
            novamira_oauth_json('servers', $mcp_name, ['type' => 'http', 'url' => $mcp_url]),
            novamira_oauth_add_to('mcp.json'),
            [__('Project', domain: 'novamira') => '.github/copilot/mcp.json'],
        ),
        'windsurf' => novamira_oauth_code_entry(
            novamira_oauth_json('mcpServers', $mcp_name, ['serverUrl' => $mcp_url]),
            novamira_oauth_add_to('mcp_config.json'),
            [
                'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
                'Windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
            ],
        ),
    ];
}

/**
 * mcp-remote bridge configs for every client except the browser-only Claude.ai. Used for the
 * whole list on local sites and for the unverified tail on public sites. File paths mirror
 * novamira_build_configs()'s locations, kept here so the OAuth flow stays isolated from the
 * proven app-password renderer.
 *
 * @param array<string, string> $env
 * @return array<string, array<string, mixed>>
 */
function novamira_build_oauth_bridge_configs(string $mcp_url, string $mcp_name, array $env): array
{
    $server = novamira_oauth_bridge_server($mcp_url, $env);

    return array_merge(
        novamira_build_oauth_bridge_special($mcp_url, $mcp_name, $env, $server),
        novamira_build_oauth_bridge_standard(
            novamira_oauth_json('mcpServers', $mcp_name, $server),
            novamira_oauth_json('servers', $mcp_name, $server),
        ),
    );
}

/**
 * Bridge entries whose format is unique to the client (shell command, TOML, or a bespoke JSON
 * envelope) rather than the shared mcpServers/servers payload.
 *
 * @param array<string, string> $env
 * @param array<string, mixed>  $server
 * @return array<string, array<string, mixed>>
 */
function novamira_build_oauth_bridge_special(string $mcp_url, string $mcp_name, array $env, array $server): array
{
    $opts = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    $zed_json = (string) json_encode([
        'context_servers' => [$mcp_name => array_merge(['source' => 'custom', 'enabled' => true], $server)],
    ], $opts);

    $opencode_server = ['type' => 'local', 'command' => ['npx', '-y', 'mcp-remote', $mcp_url]];
    if ($env !== []) {
        $opencode_server['environment'] = $env;
    }
    $opencode_json = (string) json_encode(['mcp' => [$mcp_name => $opencode_server]], $opts);

    return [
        'claude-code' => [
            'kind' => 'code',
            'code' => novamira_oauth_bridge_claude_code($mcp_name, $mcp_url, $env),
            'hint' => __(
                'Run in your terminal to make this server available in all your projects, then sign in when your browser opens.',
                domain: 'novamira',
            ),
            'paths' => [],
            'isShell' => true,
        ],
        'codex-app' => novamira_oauth_code_entry(
            novamira_oauth_bridge_codex($mcp_name, $mcp_url, $env),
            novamira_oauth_add_to('config.toml'),
            ['macOS / Linux' => '~/.codex/config.toml', 'Windows' => '%USERPROFILE%\\.codex\\config.toml'],
        ),
        'codex-cli' => [
            'kind' => 'code',
            'code' => novamira_oauth_codex_bridge_command($mcp_name, $mcp_url, $env),
            'hint' => __(
                'Run in your terminal. Codex saves the server in your user configuration, shared across projects.',
                domain: 'novamira',
            ),
            'paths' => [],
            'isShell' => true,
        ],
        'zed' => novamira_oauth_code_entry($zed_json, novamira_oauth_add_to('settings.json'), [
            'macOS / Linux' => '~/.config/zed/settings.json',
        ]),
        'opencode' => novamira_oauth_code_entry($opencode_json, novamira_oauth_add_to('opencode.json'), [
            __('Project', domain: 'novamira') => 'opencode.json',
            __('Global', domain: 'novamira') => '~/.config/opencode/opencode.json',
        ]),
    ];
}

/**
 * Bridge entries that reuse the shared mcpServers/servers JSON payloads. File paths mirror
 * novamira_build_configs()'s locations.
 *
 * @return array<string, array<string, mixed>>
 */
function novamira_build_oauth_bridge_standard(string $mcp_servers_json, string $servers_json): array
{
    return [
        'claude-desktop' => novamira_oauth_code_entry(
            $mcp_servers_json,
            novamira_oauth_add_to('claude_desktop_config.json'),
            [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
            ],
        ),
        'antigravity' => novamira_oauth_code_entry($mcp_servers_json, novamira_oauth_add_to('mcp_config.json'), [
            __('Global (macOS / Linux)', domain: 'novamira') => '~/.gemini/config/mcp_config.json',
            __('Global (Windows)', domain: 'novamira') => '%USERPROFILE%\\.gemini\\config\\mcp_config.json',
            __('Workspace', domain: 'novamira') => '.agents/mcp_config.json',
        ]),
        'antigravity-cli' => novamira_oauth_code_entry($mcp_servers_json, novamira_oauth_add_to('mcp_config.json'), [
            __('Global (macOS / Linux)', domain: 'novamira') => '~/.gemini/config/mcp_config.json',
            __('Global (Windows)', domain: 'novamira') => '%USERPROFILE%\\.gemini\\config\\mcp_config.json',
            __('Workspace', domain: 'novamira') => '.agents/mcp_config.json',
        ]),
        'cursor' => novamira_oauth_code_entry($mcp_servers_json, novamira_oauth_add_to('mcp.json'), [
            __('Global', domain: 'novamira') => '~/.cursor/mcp.json',
            __('Project', domain: 'novamira') => '.cursor/mcp.json',
        ]),
        'vscode' => novamira_oauth_code_entry($servers_json, novamira_oauth_add_to('mcp.json'), [
            __('Workspace', domain: 'novamira') => '.vscode/mcp.json',
            __('User', domain: 'novamira') => __(
                'Run: MCP: Open User Configuration (command palette)',
                domain: 'novamira',
            ),
        ]),
        'github-copilot' => novamira_oauth_code_entry($servers_json, novamira_oauth_add_to('mcp.json'), [
            __('Project', domain: 'novamira') => '.github/copilot/mcp.json',
        ]),
        'windsurf' => novamira_oauth_code_entry($mcp_servers_json, novamira_oauth_add_to('mcp_config.json'), [
            'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
            'Windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
        ]),
        'cline' => novamira_oauth_code_entry($mcp_servers_json, novamira_oauth_add_to('cline_mcp_settings.json'), [
            __('Via UI', domain: 'novamira') => __(
                'Cline sidebar → MCP Servers → Configure MCP Servers',
                domain: 'novamira',
            ),
        ]),
        'roo-code' => novamira_oauth_code_entry($mcp_servers_json, novamira_oauth_add_to('mcp.json'), [
            __('Project', domain: 'novamira') => '.roo/mcp.json',
            __('Via UI', domain: 'novamira') => __(
                'Roo Code sidebar → MCP Servers → Configure MCP Servers',
                domain: 'novamira',
            ),
        ]),
        'amazon-q' => novamira_oauth_code_entry($mcp_servers_json, novamira_oauth_add_to('mcp.json'), [
            __('Global', domain: 'novamira') => '~/.aws/amazonq/mcp.json',
            __('Project', domain: 'novamira') => '.amazonq/mcp.json',
        ]),
        'kilo-code' => novamira_oauth_code_entry($mcp_servers_json, novamira_oauth_add_to('mcp.json'), [
            __('Project', domain: 'novamira') => '.kilocode/mcp.json',
            __('Via UI', domain: 'novamira') => __(
                'Kilo Code sidebar → MCP Servers → Configure MCP Servers',
                domain: 'novamira',
            ),
        ]),
    ];
}
