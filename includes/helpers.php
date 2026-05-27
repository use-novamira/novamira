<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Shared helper functions for Novamira.
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resolve a filesystem path, ensuring it stays within the allowed base directory.
 *
 * @param string $path       The path to resolve. Relative paths are prepended with ABSPATH.
 * @param bool   $must_exist Whether the path must already exist.
 * @return string|WP_Error   The resolved absolute path, or WP_Error on failure.
 */
function novamira_resolve_path($path, $must_exist = false)
{
    // Prepend ABSPATH to relative paths.
    if (!str_starts_with($path, '/') && !str_starts_with($path, '\\')) {
        $path = ABSPATH . $path;
    }

    /**
     * Filter the base directory for filesystem operations.
     * Return false to disable the base directory restriction entirely.
     *
     * @param string $base_dir The base directory. Defaults to ABSPATH.
     */
    /** @var string|false $base_dir */
    $base_dir = apply_filters('novamira_filesystem_base_dir', ABSPATH);

    // Resolve path that may not exist yet via parent directory.
    $resolved_parent = realpath(dirname($path));
    $resolved = $resolved_parent !== false ? $resolved_parent . DIRECTORY_SEPARATOR . basename($path) : $path;

    // For paths that must exist, override with realpath.
    if ($must_exist) {
        $resolved = realpath($path);
        if ($resolved === false) {
            return new WP_Error('path_not_found', sprintf(__('Path does not exist: %s', domain: 'novamira'), $path));
        }
    }

    // Enforce base directory restriction.
    if ($base_dir !== false) {
        $real_base = realpath($base_dir);
        if ($real_base === false) {
            $real_base = rtrim($base_dir, characters: '/\\');
        }

        if (!str_starts_with($resolved, $real_base)) {
            return new WP_Error('path_outside_base', sprintf(
                __('Path "%s" is outside the allowed base directory "%s".', domain: 'novamira'),
                $resolved,
                $real_base,
            ));
        }
    }

    return $resolved;
}

/**
 * Get the sandbox directory path for AI-written PHP plugins.
 *
 * @param bool $ensure_exists Whether to create the directory if it doesn't exist.
 * @return string Absolute path to the sandbox directory (with trailing slash).
 */
function novamira_get_sandbox_dir($ensure_exists = false)
{
    if ($ensure_exists && !is_dir(NOVAMIRA_SANDBOX_DIR)) {
        wp_mkdir_p(NOVAMIRA_SANDBOX_DIR);
    }

    return NOVAMIRA_SANDBOX_DIR;
}

/**
 * Validate that a resolved path is inside the sandbox directory.
 *
 * @param string $resolved The resolved absolute path to check.
 * @return true|WP_Error True if inside the sandbox, WP_Error otherwise.
 */
function novamira_validate_sandbox_path($resolved)
{
    $sandbox_dir = novamira_get_sandbox_dir();
    $real_sandbox = realpath($sandbox_dir);
    if ($real_sandbox === false) {
        return new WP_Error('sandbox_not_found', __('The sandbox directory does not exist.', domain: 'novamira'));
    }

    $real_resolved = realpath($resolved);
    if ($real_resolved === false) {
        $real_resolved = $resolved;
    }

    if (!str_starts_with($real_resolved, $real_sandbox . DIRECTORY_SEPARATOR)) {
        return new WP_Error('outside_sandbox', sprintf(
            /* translators: %s: sandbox directory path */
            __('Only files inside the sandbox (%s) can be modified.', domain: 'novamira'),
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Check that a resolved PHP file path is inside the sandbox directory.
 *
 * @param string $resolved Absolute resolved path to the PHP file.
 * @return bool|WP_Error True if valid, WP_Error if outside sandbox.
 */
function novamira_check_php_sandbox(string $resolved): bool|WP_Error
{
    $sandbox_dir = novamira_get_sandbox_dir(ensure_exists: false);
    $real_sandbox = realpath($sandbox_dir);
    $parent_dir = realpath(dirname($resolved));

    // If sandbox doesn't exist yet, compare normalized paths.
    if ($real_sandbox === false) {
        $real_sandbox = rtrim(string: $sandbox_dir, characters: '/\\');
    }
    if ($parent_dir === false) {
        $parent_dir = dirname($resolved);
    }

    if (!str_starts_with($parent_dir, $real_sandbox)) {
        return new WP_Error('php_sandbox_required', sprintf(
            'PHP files can only be written to the sandbox directory: %s. Use a path like "wp-content/novamira-sandbox/my-feature.php".',
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Create a parent directory and return the list of directories that were created.
 *
 * @param string $parent_dir Absolute path to the parent directory.
 * @return array|WP_Error List of directories created, or WP_Error on failure.
 */
function novamira_ensure_parent_dir(string $parent_dir): array|WP_Error
{
    if (is_dir($parent_dir)) {
        return [];
    }

    // Collect which directories will be created.
    $dir_to_check = $parent_dir;
    $dirs_to_create = [];
    while (!is_dir($dir_to_check)) {
        $dirs_to_create[] = $dir_to_check;
        $dir_to_check = dirname($dir_to_check);
    }
    $directories_created = array_reverse($dirs_to_create);

    if (!mkdir(directory: $parent_dir, permissions: 0755, recursive: true)) {
        return new WP_Error('mkdir_failed', sprintf('Failed to create directory: %s', $parent_dir));
    }

    return $directories_created;
}

/**
 * Check whether a filename ends with the ".disabled" suffix.
 *
 * @param string $path File path to check.
 * @return bool
 */
function novamira_is_disabled_file($path)
{
    return str_ends_with($path, '.disabled');
}

/**
 * Check whether the AI abilities are enabled via the settings option.
 *
 * @return bool
 */
function novamira_is_enabled()
{
    /** @var mixed $value */
    $value = get_option('novamira_ai_abilities_enabled', default_value: false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    // Abilities are locked to the domain they were enabled on.
    /** @var string $locked_domain */
    $locked_domain = get_option('novamira_ai_abilities_domain', default_value: '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain === $current_domain;
}

/**
 * Heuristic: does this site look like a production environment?
 *
 * Default to production when in doubt — the warning's job is to prompt the user to think
 * twice before enabling AI Abilities on something live. Hostnames and `wp_get_environment_type()`
 * results that strongly suggest staging/dev/local short-circuit to `false`.
 *
 * @return bool
 */
function novamira_looks_like_production(): bool
{
    $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $host = strtolower($host);
    if ($host === '') {
        return true;
    }

    // Strip an eventual port suffix.
    $colon_pos = strpos(haystack: $host, needle: ':');
    if ($colon_pos !== false) {
        $host = substr($host, offset: 0, length: $colon_pos);
    }

    // No dot at all (e.g. "localhost", "wordpress") → not production.
    if (!str_contains($host, '.')) {
        return false;
    }

    // IP literals → not production.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return false;
    }

    $segments = explode('.', $host);
    $tld = end($segments);

    /** @var array<int, string> $non_prod_tlds */
    $non_prod_tlds = apply_filters('novamira_non_production_tlds', [
        'dev',
        'local',
        'staging',
        'test',
        'example',
        'invalid',
        'backup',
    ]);

    if (in_array($tld, $non_prod_tlds, strict: true)) {
        return false;
    }

    /** @var array<int, string> $non_prod_subdomain_segments */
    $non_prod_subdomain_segments = apply_filters('novamira_non_production_subdomain_segments', [
        'dev',
        'local',
        'test',
        'staging',
        'stage',
        'stg',
        'wp-staging',
        'wpstaging',
        'development',
        'wptest',
        'backup',
        'preview',
        'preprod',
        'qa',
        'uat',
        'sandbox',
        'demo',
        'beta',
        'mirror',
    ]);

    foreach ($segments as $segment) {
        if (in_array($segment, $non_prod_subdomain_segments, strict: true)) {
            return false;
        }
    }

    /** @var array<int, string> $non_prod_keyword_regex_words */
    $non_prod_keyword_regex_words = apply_filters('novamira_non_production_keyword_words', [
        'test',
        'dev',
        'staging',
        'stage',
        'stg',
        'local',
        'wp-staging',
        'development',
        'wptest',
        'backup',
        'preview',
        'preprod',
        'sandbox',
        'demo',
        'beta',
    ]);

    $alternation = implode('|', array_map(static fn(string $w): string => preg_quote(
        str: $w,
        delimiter: '/',
    ), $non_prod_keyword_regex_words));
    if ($alternation !== '' && preg_match('/\b(?:' . $alternation . ')[0-9]*\b/i', $host) === 1) {
        return false;
    }

    /** @var array<int, string> $non_prod_host_suffixes */
    $non_prod_host_suffixes = apply_filters('novamira_production_host_patterns', [
        'wpengine.com',
        'wpenginepowered.com',
        'sg-host.com',
        'cloudwaysapps.com',
        'closte.com',
        'runcloud.link',
        'kinsta.cloud',
        'pantheonsite.io',
        'onrocket.site',
        'pressdns.com',
        'bigscoots-staging.com',
        'flywheelstaging.com',
        'wpstage.net',
        'wpserveur.net',
        'myftpupload.com',
        'myraidbox.de',
        'elementor.cloud',
        'lndo.site',
        'ddev.site',
    ]);

    foreach ($non_prod_host_suffixes as $suffix) {
        if ($suffix !== '' && str_ends_with($host, $suffix)) {
            return false;
        }
    }

    if (function_exists('wp_get_environment_type')) {
        $env = wp_get_environment_type();
        if (in_array($env, ['staging', 'development', 'local'], strict: true)) {
            return false;
        }
    }

    return true;
}

/**
 * Heuristic: is this site likely served over plain HTTP on a local hostname?
 *
 * WordPress core blocks Application Passwords on HTTP unless `WP_ENVIRONMENT_TYPE` is set to
 * 'local'. Detecting this lets us surface the exact wp-config snippet the user needs.
 */
function novamira_likely_local_http(): bool
{
    $home = home_url();
    if (!str_starts_with(strtolower($home), 'http://')) {
        return false;
    }

    $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    /** @var array<int, string> $local_substrings */
    $local_substrings = apply_filters('novamira_self_signed_host_patterns', [
        '.local',
        '.test',
        'localhost',
        '.lndo.site',
        '.ddev.site',
    ]);

    foreach ($local_substrings as $needle) {
        if ($needle !== '' && str_contains($host, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Heuristic: is this site likely served from an HTTPS endpoint with a self-signed certificate?
 *
 * LocalWP, DDEV, Lando and similar dev tools commonly serve `.local` / `.test` hostnames over
 * HTTPS with self-signed certs. The @automattic/mcp-wordpress-remote npx package rejects such
 * certs by default, so the MCP client cannot connect unless `NODE_TLS_REJECT_UNAUTHORIZED=0` is
 * passed in the env. Detecting this lets us inject that env var and warn the user about the trade.
 */
function novamira_likely_self_signed_https(): bool
{
    $home = home_url();
    if (!str_starts_with(strtolower($home), 'https://')) {
        return false;
    }

    $host = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    /** @var array<int, string> $self_signed_substrings */
    $self_signed_substrings = apply_filters('novamira_self_signed_host_patterns', [
        '.local',
        '.test',
        'localhost',
        '.lndo.site',
        '.ddev.site',
    ]);

    foreach ($self_signed_substrings as $needle) {
        if ($needle !== '' && str_contains($host, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Has the current user dismissed the production warning?
 */
function novamira_production_warning_dismissed(): bool
{
    /** @var mixed $value */
    $value = get_user_meta(get_current_user_id(), key: 'novamira_production_warning_dismissed', single: true);
    return $value === '1' || $value === 1 || $value === true;
}

/**
 * Handle the dismiss-production-warning form submission. Called from admin_init.
 */
function novamira_handle_dismiss_production_warning(): void
{
    if (($_POST['novamira_dismiss_production_warning'] ?? null) === null) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    check_admin_referer('novamira_dismiss_production_warning');

    update_user_meta(get_current_user_id(), meta_key: 'novamira_production_warning_dismissed', meta_value: '1');

    wp_safe_redirect(admin_url('admin.php?page=novamira-connect'));
    exit();
}

/**
 * Check whether abilities are nominally enabled but inactive due to a domain mismatch.
 *
 * @return bool
 */
function novamira_is_domain_mismatch()
{
    /** @var mixed $value */
    $value = get_option('novamira_ai_abilities_enabled', default_value: false);
    if ($value !== '1' && $value !== true) {
        return false;
    }

    /** @var string $locked_domain */
    $locked_domain = get_option('novamira_ai_abilities_domain', default_value: '');
    $current_domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);

    return $locked_domain !== $current_domain;
}

/**
 * Report whether WordPress Application Passwords are available, and why not if not.
 *
 * Distinguishes between the HTTPS/local-env requirement (`wp_is_application_passwords_supported()`)
 * and a filter-based override (typical of security plugins hooking `wp_is_application_passwords_available`).
 *
 * @return array{available: bool, reason: 'available'|'unsupported'|'filtered', message: string}
 */
function novamira_app_passwords_status(): array
{
    if (wp_is_application_passwords_available()) {
        return ['available' => true, 'reason' => 'available', 'message' => ''];
    }

    if (!wp_is_application_passwords_supported()) {
        return [
            'available' => false,
            'reason' => 'unsupported',
            'message' => __(
                'Application Passwords require HTTPS or WP_ENVIRONMENT_TYPE set to "local".',
                domain: 'novamira',
            ),
        ];
    }

    return [
        'available' => false,
        'reason' => 'filtered',
        'message' => __(
            'Application Passwords have been disabled on this site, likely by a security plugin. Check your security plugin settings (e.g. Solid Security, Wordfence, All In One WP Security) and re-enable Application Passwords to continue.',
            domain: 'novamira',
        ),
    ];
}

/**
 * Build a combined date/time format string from WordPress settings.
 *
 * Falls back to 'Y-m-d H:i:s' if either format is empty.
 *
 * @param string $fallback Optional fallback format.
 * @return string
 */
function novamira_get_datetime_format($fallback = 'Y-m-d H:i:s')
{
    $date_format = (string) get_option('date_format');
    $time_format = (string) get_option('time_format');

    if ($date_format === '' || $time_format === '') {
        return $fallback;
    }

    return $date_format . ' ' . $time_format;
}

/**
 * Permission callback: requires manage_options capability.
 *
 * @return bool
 */
function novamira_permission_callback()
{
    return current_user_can('manage_options');
}

/**
 * Return a bearer token from a REST request header.
 */
function novamira_rest_header_token(WP_REST_Request $request, string $header_name): string
{
    $header_token = $request->get_header($header_name);
    if (is_string($header_token) && trim($header_token) !== '') {
        return trim($header_token);
    }

    $authorization = $request->get_header('authorization');
    if (!is_string($authorization)) {
        return '';
    }

    $matches = [];
    if (preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches) !== 1) {
        return '';
    }

    return trim($matches[1]);
}

/**
 * Detect active languages from multilingual plugins (WPML, Polylang, TranslatePress).
 *
 * @return array{plugin: string, languages: string[]}|null Plugin name and language codes, or null if no multilingual plugin is active.
 */
function novamira_get_active_languages()
{
    // WPML.
    if (function_exists('icl_get_languages')) {
        /** @var array<string, array{language_code: string}>|false $wpml_languages */
        $wpml_languages = icl_get_languages('skip_missing=0');
        if (is_array($wpml_languages)) {
            return ['plugin' => 'WPML', 'languages' => array_column($wpml_languages, 'language_code')];
        }
    }

    // Polylang.
    if (function_exists('pll_languages_list')) {
        /** @var string[]|false $languages */
        $languages = pll_languages_list();
        if (is_array($languages)) {
            return ['plugin' => 'Polylang', 'languages' => $languages];
        }
    }

    // TranslatePress.
    if (class_exists('TRP_Translate_Press')) {
        /** @var array{translation-languages?: string[]} $trp_settings */
        $trp_settings = get_option('trp_settings', default_value: []);
        return ['plugin' => 'TranslatePress', 'languages' => $trp_settings['translation-languages'] ?? []];
    }

    return null;
}

/**
 * Build the MCP server instructions sent to AI agents during initialization.
 *
 * Includes environment info (PHP/WP versions, plugins) and guidance on using
 * WordPress-native features instead of hardcoding data in PHP.
 *
 * @return string
 */
function novamira_build_server_instructions()
{
    $lines = [
        'Novamira gives you unrestricted control over this WordPress installation.',
        '',
        '## Environment',
        '',
        'WordPress ' . get_bloginfo('version') . ' — PHP ' . PHP_VERSION . ' — Locale: ' . get_locale(),
    ];

    // Detect active languages from multilingual plugins.
    $multilingual = novamira_get_active_languages();
    if ($multilingual !== null && $multilingual['languages'] !== []) {
        $lines[] = 'Multilingual (' . $multilingual['plugin'] . '): ' . implode(', ', $multilingual['languages']);
    }

    $lines[] = '';

    if (function_exists('get_plugins')) {
        /** @var array<string, array{Name?: string, Version?: string}> $all_plugins */
        $all_plugins = get_plugins();
        if ($all_plugins !== []) {
            $lines[] = 'Installed plugins:';
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                $name = $plugin_data['Name'] ?? $plugin_file;
                $version = $plugin_data['Version'] ?? '';
                $version_suffix = $version !== '' ? ' v' . $version : '';
                $active = is_plugin_active($plugin_file) ? 'active' : 'inactive';
                $lines[] = '- ' . $name . $version_suffix . ' (' . $active . ')';
            }
            $lines[] = '';
        }
    }

    $lines = array_merge($lines, [
        '## WordPress-native development',
        '',
        'IMPORTANT: Prefer WordPress-native features to store and manage data.',
        'Do not hardcode content in PHP arrays when WordPress has a better mechanism:',
        '- Custom post types (register_post_type) for structured content (unless a data-modeling plugin owns it — see below)',
        '- Taxonomies (register_taxonomy) for categorization (same caveat)',
        '- Post meta / custom fields (update_post_meta) for additional data on posts (same caveat)',
        '- Options API (update_option) for settings and configuration',
        '- Custom database tables via $wpdb only when the above are insufficient',
        '',
        'Take advantage of active plugins. If a data-modeling plugin is in the',
        'installed-plugins inventory above (ACF / ACF Pro, JetEngine, Pods, ACPT,',
        'Meta Box, Toolset, Custom Post Type UI, WooCommerce, etc.), use it for the',
        'task it owns — never write a custom register_post_type / register_taxonomy /',
        'register_meta call in PHP for content the active plugin can model through its',
        'own UI/API. Splitting the source of truth between custom PHP and a plugin UI',
        'produces broken slugs, labels, and capabilities the next time the user touches',
        'either side, and that recovery is hard. If two or more such plugins are active,',
        'ask the user which one to use before persisting anything.',
        '',
        'Use WordPress hooks (actions/filters), template hierarchy, and REST API',
        'conventions. Write code that integrates with WordPress, not code that ignores it.',
    ]);

    return implode("\n", $lines);
}

/**
 * Render the branded admin header with logo and background color.
 */
function novamira_render_admin_header(): void
{ ?>
    <style>
        .novamira-admin-header-wrap {
            background: #000;
            margin: -1px 0 0 -20px;
            padding: 20px 20px 20px 22px;
        }
        .novamira-admin-header {
            margin: 0 auto;
            display: flex;
            align-items: center;
        }
        .novamira-admin-header img {
            max-height: 40px;
        }
        @media screen and (max-width: 782px) {
            .novamira-admin-header-wrap {
                margin: -1px 0 0 -10px;
                padding: 15px;
            }
            .novamira-admin-header {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
    <div class="novamira-admin-header-wrap">
        <div class="novamira-admin-header">
            <img src="<?php echo
                esc_url((string) NOVAMIRA_PLUGIN_URL . 'assets/novamira_logo.svg')
            ; ?>" alt="Novamira" width="200" height="40">
        </div>
    </div>
    <?php }
