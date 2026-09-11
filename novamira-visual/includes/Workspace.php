<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace NovamiraVisual;

if (!defined('ABSPATH')) {
    exit();
}

\add_action('template_redirect', __NAMESPACE__ . '\\redirect_workspace_path');

function redirect_workspace_path(): void
{
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if ($request_uri === '') {
        return;
    }

    /** @var mixed $path */
    $path = wp_parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($path) || trim($path, characters: '/') !== 'wp-admin/novamira-visual-workspace') {
        return;
    }

    $workspace_url = \admin_url('admin-post.php?action=novamira-visual');
    if (!\is_user_logged_in()) {
        \wp_safe_redirect(\wp_login_url($workspace_url));
        exit();
    }

    if (!\novamira_current_user_can_manage()) {
        \wp_die(
            message: \esc_html__('You do not have permission to access Novamira Visual.', domain: 'novamira'),
            title: \esc_html__('Novamira Visual', domain: 'novamira'),
            args: ['response' => 403],
        );
    }

    \wp_safe_redirect($workspace_url);
    exit();
}

class Workspace
{
    public function __construct()
    {
        \add_action('admin_menu', [$this, 'add_menu'], priority: 80);
        \add_action('admin_post_novamira-visual', [$this, 'render_standalone_page']);
        \add_action('admin_post_nopriv_novamira-visual', [$this, 'redirect_to_login']);
        \add_action('wp_ajax_novamira_visual_auth_check', [$this, 'ajax_auth_check']);
        \add_action('wp_ajax_nopriv_novamira_visual_auth_check', [$this, 'ajax_auth_check']);
        \add_action('wp_ajax_novamira_visual_set_abilities', [$this, 'ajax_set_abilities']);
        \add_action('admin_post_novamira_visual_download_mcpb', [$this, 'handle_download_mcpb']);
    }

    public function ajax_auth_check(): void
    {
        $logged_in = \is_user_logged_in();
        $can_manage = $logged_in && \novamira_current_user_can_manage();
        $workspace_url = $this->get_workspace_url();

        \wp_send_json_success([
            'authenticated' => $can_manage,
            'loggedIn' => $logged_in,
            'canManage' => $can_manage,
            'recoverUrl' => $logged_in ? $workspace_url : \wp_login_url($workspace_url),
        ]);
    }

    public function ajax_set_abilities(): void
    {
        \check_ajax_referer(action: 'wp_rest', query_arg: 'nonce');
        if (!\novamira_current_user_can_manage()) {
            \wp_send_json_error([
                'message' => \__('You do not have permission to change AI Abilities.', domain: 'novamira'),
            ], status_code: 403);
        }

        $raw = file_get_contents('php://input');
        /** @var mixed $params */
        $params = is_string($raw) && $raw !== '' ? json_decode($raw, associative: true) : [];
        $enable = is_array($params) && ($params['enabled'] ?? false) === true;

        $toggle = $enable ? 'novamira_enable_ai_abilities' : 'novamira_disable_ai_abilities';
        if (function_exists($toggle)) {
            \call_user_func($toggle);
        }

        \wp_send_json_success(['enabled' => function_exists('novamira_is_enabled') && \novamira_is_enabled()]);
    }

    public function add_menu(): void
    {
        if (!defined('NOVAMIRA_VERSION')) {
            return;
        }

        $hook = \add_submenu_page(
            parent_slug: 'novamira-connect',
            page_title: \__('Novamira Visual', domain: 'novamira'),
            menu_title: \sprintf(
                '%s <span class="awaiting-mod">%s</span>',
                \esc_html__('Visual', domain: 'novamira'),
                \esc_html__('Experimental', domain: 'novamira'),
            ),
            capability: \novamira_manage_capability(),
            menu_slug: 'novamira-visual',
            callback: [$this, 'render_page'],
        );

        if ($hook === false) {
            return;
        }

        \add_action('load-' . $hook, [$this, 'redirect_to_standalone_page']);
    }

    public function redirect_to_standalone_page(): void
    {
        if (!\novamira_current_user_can_manage()) {
            return;
        }

        \wp_safe_redirect($this->get_workspace_url());
        exit();
    }

    public function render_page(): void
    {
        if (!\novamira_current_user_can_manage()) {
            return;
        }
        ?>
        <div class="wrap">
            <p>
                <a href="<?php echo \esc_url($this->get_workspace_url()); ?>" class="button button-primary">
                    <?php echo \esc_html__('Open Novamira Visual', domain: 'novamira'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public function render_standalone_page(): void
    {
        if (!\novamira_current_user_can_manage()) {
            \wp_die(
                message: \esc_html__('You do not have permission to access Novamira Visual.', domain: 'novamira'),
                title: \esc_html__('Novamira Visual', domain: 'novamira'),
                args: ['response' => 403],
            );
        }

        $this->enqueue_workspace_assets();
        \wp_enqueue_style('buttons');

        ?>
        <!doctype html>
        <html <?php \language_attributes(); ?> class="novamira-visual-workspace-document">
        <head>
            <meta charset="<?php \bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo \esc_html__('Novamira Visual', domain: 'novamira'); ?></title>
            <?php \wp_print_styles(); ?>
        </head>
        <body class="novamira-visual-workspace-document">
            <div id="novamira-visual-workspace-root"></div>
            <?php \wp_print_footer_scripts(); ?>
        </body>
        </html>
        <?php

        exit();
    }

    public function redirect_to_login(): void
    {
        /** @var array<string, mixed> $request_params */
        $request_params = \wp_unslash($_GET);
        $redirect_to = \add_query_arg($request_params, \admin_url('admin-post.php'));

        \wp_safe_redirect(\wp_login_url($redirect_to));
        exit();
    }

    private function enqueue_workspace_assets(): void
    {
        $asset_file = NOVAMIRA_VISUAL_PLUGIN_DIR . 'dist/workspace.js';
        $version = \Novamira\Visual\asset_version($asset_file);

        \wp_enqueue_script(
            'novamira-visual-workspace',
            NOVAMIRA_VISUAL_PLUGIN_URL . 'dist/workspace.js',
            [],
            $version,
            args: true,
        );

        $abilities_enabled = function_exists('novamira_is_enabled') && \novamira_is_enabled();
        $looks_production = function_exists('novamira_looks_like_production') && \novamira_looks_like_production();

        \wp_localize_script('novamira-visual-workspace', object_name: 'novamiraVisualWorkspaceData', l10n: [
            'adminUrl' => \esc_url_raw(\admin_url()),
            'siteUrl' => \esc_url_raw(\home_url('/')),
            'siteName' => (string) \get_bloginfo('name'),
            'serverName' => $this->server_name(),
            'restUrl' => \esc_url_raw(\rest_url('novamira-visual/v1/')),
            'workspaceUrl' => \esc_url_raw($this->get_workspace_url()),
            'mcpbUrl' => \esc_url_raw(\wp_nonce_url(
                \admin_url('admin-post.php?action=novamira_visual_download_mcpb'),
                'novamira_visual_download_mcpb',
            )),
            'authCheckUrl' => \esc_url_raw(\admin_url('admin-ajax.php?action=novamira_visual_auth_check')),
            'loginUrl' => \esc_url_raw(\wp_login_url($this->get_workspace_url())),
            'visualVersion' => (string) NOVAMIRA_VISUAL_VERSION,
            'abilitiesEnabled' => $abilities_enabled,
            'looksProduction' => $looks_production,
            'nonce' => \wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Stream a downloadable .mcpb bundle for Claude Desktop. Hooked on admin_post.
     *
     * Unlike the core Novamira bundle, the Visual bundle carries no credentials:
     * it only points the npx MCP server at this site's workspace URL, so it is
     * safe to keep and share.
     */
    public function handle_download_mcpb(): void
    {
        if (!\novamira_current_user_can_manage()) {
            \wp_die(\esc_html__('You are not allowed to download this bundle.', domain: 'novamira'));
        }

        \check_admin_referer('novamira_visual_download_mcpb');

        if (!\class_exists('ZipArchive')) {
            \wp_die(\esc_html__(
                'Cannot build the bundle: the PHP zip extension is not available on this server. Use the JSON config instead.',
                domain: 'novamira',
            ));
        }

        $manifest = $this->build_mcpb_manifest($this->get_workspace_url(), $this->server_name());
        $manifest_json = (string) \wp_json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $stub =
            "// Placeholder entry point. The actual MCP server is launched via mcp_config\n"
            . "// (npx novamira-visual-mcp), so this file is never executed.\n"
            . "// It exists only to satisfy the manifest's required entry_point field.\n";

        $tmp = \wp_tempnam('novamira-visual-mcpb');
        $zip = new \ZipArchive();
        if ($tmp === '' || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            \wp_die(\esc_html__('Could not create the bundle archive.', domain: 'novamira'));
        }
        $zip->addFromString('manifest.json', $manifest_json);
        $zip->addFromString('server/index.js', $stub);
        $zip->close();

        $host = (string) \wp_parse_url(\home_url(), PHP_URL_HOST);
        $filename = 'novamira-visual-' . \sanitize_file_name($host !== '' ? $host : 'site') . '.mcpb';

        \nocache_headers();
        \header('Content-Type: application/octet-stream');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Content-Length: ' . (string) \filesize($tmp));
        \readfile($tmp);
        \wp_delete_file($tmp);
        exit();
    }

    /**
     * Build the MCPB manifest (manifest.json contents) for this site's workspace.
     * The server is launched with npx and pointed at the workspace URL; no
     * credentials are embedded.
     *
     * @return array<string, mixed>
     */
    private function build_mcpb_manifest(string $workspace_url, string $server_name): array
    {
        $site_name = \novamira_plain_site_name((string) \get_bloginfo('name'));
        $display_name = $site_name !== '' ? 'Novamira Visual: ' . $site_name : 'Novamira Visual';

        return [
            'manifest_version' => '0.3',
            'name' => $server_name,
            'display_name' => $display_name,
            'version' => (string) NOVAMIRA_VISUAL_VERSION,
            'description' => \__(
                'Watch your AI agent work on this WordPress site, live in a browser workspace.',
                domain: 'novamira',
            ),
            'author' => ['name' => 'Novamira'],
            'server' => [
                // entry_point is required by the MCPB schema even though the server
                // is launched via mcp_config (npx); the bundled stub is never run.
                'type' => 'node',
                'entry_point' => 'server/index.js',
                'mcp_config' => [
                    'command' => 'npx',
                    'args' => ['-y', 'novamira-visual-mcp@latest'],
                    'env' => ['NOVAMIRA_VISUAL_WORKSPACE_URL' => $workspace_url],
                ],
            ],
        ];
    }

    /**
     * Unique MCP server name for this site, capped at 25 characters. The same value
     * names the .mcpb bundle and is passed to the workspace for its JSON configs.
     *
     * The shared helpers live in includes/connect-page.php, which novamira.php loads
     * unconditionally before any feature (this one included) boots on plugins_loaded.
     */
    private function server_name(): string
    {
        $site = \novamira_get_mcp_server_site();

        // Visual has always lowercased the host before stripping "www.".
        return \novamira_build_mcp_server_name_default(
            \strtolower($site['host']),
            $site['port'],
            $site['path'],
            prefix: 'novamira-visual-',
        );
    }

    private function get_workspace_url(): string
    {
        return \admin_url('admin-post.php?action=novamira-visual');
    }
}
