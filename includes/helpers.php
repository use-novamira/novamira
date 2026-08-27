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
    $resolved = novamira_resolve_candidate_path($path);

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

        if (!novamira_path_is_within_directory($resolved, $real_base)) {
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
 * Resolve an absolute candidate path while preserving a non-existing final path.
 */
function novamira_resolve_candidate_path(string $path): string
{
    $resolved_parent = realpath(dirname($path));
    if ($resolved_parent !== false) {
        return novamira_normalize_absolute_path($resolved_parent . DIRECTORY_SEPARATOR . basename($path));
    }

    return novamira_normalize_missing_path($path);
}

/**
 * Normalize a path with missing parents from the nearest existing ancestor.
 */
function novamira_normalize_missing_path(string $path): string
{
    /** @var list<string> $tail */
    $tail = [basename($path)];
    $cursor = dirname($path);
    $found_existing_ancestor = false;

    while ($cursor !== '' && $cursor !== '.' && $cursor !== dirname($cursor)) {
        $real_cursor = realpath($cursor);
        if ($real_cursor !== false) {
            $tail[] = $real_cursor;
            $found_existing_ancestor = true;
            break;
        }

        $tail[] = basename($cursor);
        $cursor = dirname($cursor);
    }

    if (!$found_existing_ancestor) {
        $real_cursor = realpath($cursor);
        if ($real_cursor !== false) {
            $tail[] = $real_cursor;
        }
        if ($real_cursor === false && str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $tail[] = DIRECTORY_SEPARATOR;
        }
    }

    return novamira_normalize_absolute_path(implode(DIRECTORY_SEPARATOR, array_reverse($tail)));
}

/**
 * Collapse "." and ".." path segments without requiring the path to exist.
 */
function novamira_normalize_absolute_path(string $path): string
{
    $path = str_replace(search: '\\', replace: DIRECTORY_SEPARATOR, subject: $path);
    $is_absolute = str_starts_with($path, DIRECTORY_SEPARATOR);
    /** @var list<string> $parts */
    $parts = [];

    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($parts);
            continue;
        }

        $parts[] = $segment;
    }

    $normalized = implode(DIRECTORY_SEPARATOR, $parts);
    if ($is_absolute) {
        return DIRECTORY_SEPARATOR . $normalized;
    }

    return $normalized === '' ? '.' : $normalized;
}

/**
 * Get the sandbox directory path for AI-written PHP plugins.
 *
 * The sandbox is an operational boundary for generated PHP: it gives Novamira
 * one place to load AI-written PHP files from, disable them, and recover from
 * crashes. It is not a security isolation boundary for all filesystem writes.
 * Authenticated Novamira administrators may intentionally read, write, edit,
 * upload, and delete non-PHP files elsewhere under the configured filesystem
 * base directory.
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

    if (!novamira_path_is_child_of_directory($real_resolved, $real_sandbox)) {
        return new WP_Error('outside_sandbox', sprintf(
            /* translators: %s: sandbox directory path */
            __('Only files inside the sandbox (%s) can be modified.', domain: 'novamira'),
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Check that a resolved PHP-execution path is inside the sandbox directory.
 *
 * This is deliberately scoped to files that Novamira may execute as PHP or
 * files that can alter PHP execution. Non-PHP filesystem access outside the
 * sandbox is expected behavior, not a sandbox bypass.
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

    if (!novamira_path_is_within_directory($parent_dir, $real_sandbox)) {
        return new WP_Error('php_sandbox_required', sprintf(
            'PHP files and PHP execution control files can only be written to the sandbox directory: %s. Use a path like "wp-content/novamira-sandbox/my-feature.php".',
            $sandbox_dir,
        ));
    }

    return true;
}

/**
 * Check whether a path can directly affect PHP execution and must stay in the sandbox.
 *
 * Do not broaden this to every writable file path unless the product model
 * changes. The sandbox is not intended to isolate all filesystem operations.
 */
function novamira_path_requires_php_sandbox(string $resolved): bool
{
    $filename = strtolower(basename($resolved));
    $extension = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));

    if ($extension === 'php') {
        return true;
    }

    return in_array(
        $filename,
        [
            '.htaccess',
            '.php.ini',
            '.user.ini',
            'php.ini',
            'web.config',
        ],
        strict: true,
    );
}

/**
 * Enforce the sandbox boundary for files that can affect PHP execution.
 *
 * Returning true for ordinary non-PHP files is intentional.
 */
function novamira_check_php_execution_sandbox(string $resolved): bool|WP_Error
{
    if (!novamira_path_requires_php_sandbox($resolved)) {
        return true;
    }

    return novamira_check_php_sandbox($resolved);
}

/**
 * Reject writes through a final path symlink.
 */
function novamira_reject_final_path_symlink(string $resolved): bool|WP_Error
{
    if (!is_link($resolved)) {
        return true;
    }

    return new WP_Error('symlink_write_rejected', sprintf('Refusing to write through symlink path: %s', $resolved));
}

/**
 * Check whether a path is equal to or contained by a directory boundary.
 */
function novamira_path_is_within_directory(string $path, string $directory): bool
{
    $normalized_path = novamira_normalize_boundary_path($path);
    $normalized_directory = novamira_normalize_boundary_path($directory);

    if ($normalized_path === $normalized_directory) {
        return true;
    }

    return novamira_path_is_child_of_normalized_directory($normalized_path, $normalized_directory);
}

/**
 * Check whether a path is contained by a directory boundary, excluding the directory itself.
 */
function novamira_path_is_child_of_directory(string $path, string $directory): bool
{
    return novamira_path_is_child_of_normalized_directory(
        novamira_normalize_boundary_path($path),
        novamira_normalize_boundary_path($directory),
    );
}

/**
 * Normalize path separators for directory-boundary comparisons.
 */
function novamira_normalize_boundary_path(string $path): string
{
    $normalized = rtrim(string: str_replace(search: '\\', replace: '/', subject: $path), characters: '/');

    return $normalized === '' ? '/' : $normalized;
}

/**
 * Check whether a normalized path is contained by a normalized directory.
 */
function novamira_path_is_child_of_normalized_directory(string $normalized_path, string $normalized_directory): bool
{
    if ($normalized_directory === '/') {
        return str_starts_with($normalized_path, '/');
    }

    return str_starts_with($normalized_path, $normalized_directory . '/');
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
    $host = novamira_normalized_home_host();
    if ($host === '') {
        return true;
    }

    if (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return false;
    }

    return (
        !novamira_host_has_non_production_tld($host)
        && !novamira_host_has_non_production_subdomain_segment($host)
        && !novamira_host_has_non_production_keyword($host)
        && !novamira_host_has_non_production_suffix($host)
        && !novamira_wp_environment_is_non_production()
    );
}

function novamira_normalized_home_host(): string
{
    $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

    // Strip an eventual port suffix.
    $colon_pos = strpos(haystack: $host, needle: ':');
    if ($colon_pos === false) {
        return $host;
    }

    return substr($host, offset: 0, length: $colon_pos);
}

function novamira_host_has_non_production_tld(string $host): bool
{
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

    return in_array($tld, $non_prod_tlds, strict: true);
}

function novamira_host_has_non_production_subdomain_segment(string $host): bool
{
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

    foreach (explode('.', $host) as $segment) {
        if (in_array($segment, $non_prod_subdomain_segments, strict: true)) {
            return true;
        }
    }

    return false;
}

function novamira_host_has_non_production_keyword(string $host): bool
{
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

    return $alternation !== '' && preg_match('/\b(?:' . $alternation . ')[0-9]*\b/i', $host) === 1;
}

function novamira_host_has_non_production_suffix(string $host): bool
{
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
        'instawp.co',
        'instawp.link',
        'instawp.xyz',
        'tastewp.com',
        'mystagingwebsite.com',
        'wpcomstaging.com',
        'convesio.cloud',
        '10web.io',
        'plesk.page',
    ]);

    foreach ($non_prod_host_suffixes as $suffix) {
        if ($suffix !== '' && str_ends_with($host, $suffix)) {
            return true;
        }
    }

    return false;
}

function novamira_wp_environment_is_non_production(): bool
{
    if (!function_exists('wp_get_environment_type')) {
        return false;
    }

    return in_array(wp_get_environment_type(), ['staging', 'development', 'local'], strict: true);
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
 * Heuristic: is this site likely served over HTTPS with a certificate the mcp-remote bridge will
 * not trust (a self-signed cert, or a local CA like mkcert that Node ignores by default)?
 *
 * LocalWP, DDEV, Lando and similar dev tools commonly serve local hostnames over HTTPS with such
 * certs, which the bridge rejects unless `NODE_TLS_REJECT_UNAUTHORIZED=0` is passed in the env.
 * Any HTTPS host that is only reachable locally is treated this way, mirroring the bridge decision
 * in novamira_build_oauth_configs(): this covers single-label hosts (e.g. "site") and private-IP
 * literals too, not only the `.local` / `.test`-style suffixes, so no local HTTPS site is left
 * without the bypass it needs.
 */
function novamira_likely_self_signed_https(): bool
{
    return str_starts_with(strtolower(home_url()), 'https://') && novamira_host_unreachable_from_cloud();
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
 * Runtime permission check for privileged Novamira administration.
 */
function novamira_current_user_can_manage(): bool
{
    return is_multisite() ? is_super_admin() : current_user_can('manage_options');
}

/**
 * Runtime permission check for a specific user.
 */
function novamira_user_can_manage(int|WP_User $user): bool
{
    $user_id = $user instanceof WP_User ? $user->ID : $user;
    if ($user_id <= 0) {
        return false;
    }

    $manage_capability = 'manage_options';

    return is_multisite() ? is_super_admin($user_id) : user_can($user, $manage_capability);
}

/**
 * Capability string for WordPress APIs that cannot accept a boolean callback.
 */
function novamira_manage_capability(): string
{
    return is_multisite() ? 'manage_network_options' : 'manage_options';
}

/**
 * Whether an Application Password belongs to Novamira.
 *
 * @param array<string, mixed> $password Application Password record.
 */
function novamira_is_application_password(array $password): bool
{
    $name = is_string($password['name'] ?? null) ? $password['name'] : '';
    return str_starts_with($name, 'Novamira');
}

/**
 * Return the persisted per-ability hub rules.
 *
 * @return array<string, array{disabled: bool}>
 */
function novamira_get_ability_rules(): array
{
    /** @var mixed $stored */
    $stored = get_option('novamira_ability_rules', default_value: []);
    if (!is_array($stored)) {
        return [];
    }

    $rules = [];
    /** @var mixed $rule */
    foreach ($stored as $ability_name => $rule) {
        if (!is_string($ability_name) || !is_array($rule) || !novamira_is_valid_ability_name($ability_name)) {
            continue;
        }
        $rules[$ability_name] = [
            'disabled' => in_array($rule['disabled'] ?? false, [true, '1', 1], strict: true),
        ];
    }

    return $rules;
}

/**
 * Persist the per-ability hub rules.
 *
 * @param array<string, array{disabled?: bool}> $rules
 */
function novamira_update_ability_rules(array $rules): void
{
    $clean = [];
    foreach ($rules as $ability_name => $rule) {
        if (!novamira_is_valid_ability_name($ability_name)) {
            continue;
        }
        if (!($rule['disabled'] ?? false)) {
            continue;
        }
        $clean[$ability_name] = ['disabled' => true];
    }

    update_option('novamira_ability_rules', $clean, autoload: false);
}

function novamira_is_valid_ability_name(string $ability_name): bool
{
    return preg_match('/^[a-z0-9-]+\/[a-z0-9-\/]+$/', $ability_name) === 1;
}

/**
 * Normalize an ability name received from a request before validating it.
 *
 * PHP normally URL-decodes form fields while populating $_POST. Some proxies
 * and security plugins can re-encode a value, leaving the namespacing slash as
 * "%2F". Decode that transport encoding before sanitize_text_field() removes
 * percent-encoded octets altogether. The strict ability-name validator remains
 * the authority on whether the resulting value is accepted.
 */
function novamira_sanitize_requested_ability_name(string $ability_name): string
{
    return sanitize_text_field(rawurldecode(wp_unslash($ability_name)));
}

function novamira_ability_can_be_managed_individually(string $ability_name): bool
{
    $ability = function_exists('wp_get_ability') ? wp_get_ability($ability_name) : null;
    $category = $ability instanceof WP_Ability ? $ability->get_category() : '';

    return \Novamira\Features\features()->feature_for_ability($ability_name, $category) === null;
}

/**
 * Whether the current request is rendering a screen that needs the complete ability registry.
 *
 * The Abilities and Features screens include resources that are currently off,
 * so the disable policy is not enforced while either screen renders.
 */
function novamira_is_ability_management_screen(): bool
{
    if (!is_admin()) {
        return false;
    }

    $page = is_string($_GET['page'] ?? null) ? sanitize_key(wp_unslash($_GET['page'])) : '';

    return in_array($page, ['novamira-abilities', 'novamira-features'], strict: true);
}

/**
 * Apply persisted Abilities Hub rules after all providers have registered.
 */
function novamira_apply_ability_policy(): void
{
    if (!function_exists('wp_get_abilities') || !function_exists('wp_unregister_ability')) {
        return;
    }

    // The Hub screen lists disabled abilities with their full metadata, so do not
    // unregister them there. Enforcement still runs on REST/MCP and front-end
    // requests, which is where ability exposure actually matters.
    if (novamira_is_ability_management_screen()) {
        return;
    }

    $rules = novamira_get_ability_rules();
    foreach (wp_get_abilities() as $ability) {
        novamira_apply_ability_policy_rule($ability, $rules);
    }
}

/**
 * @param array<string, array{disabled: bool}> $rules
 */
function novamira_apply_ability_policy_rule(WP_Ability $ability, array $rules): void
{
    $ability_name = $ability->get_name();
    $category = $ability->get_category();
    if (!\Novamira\Features\features()->is_ability_active($ability_name, $category)) {
        wp_unregister_ability($ability_name);
        return;
    }
    $rule = $rules[$ability_name] ?? null;
    if ($rule === null) {
        return;
    }

    if ($rule['disabled'] && \Novamira\Features\features()->feature_for_ability($ability_name, $category) === null) {
        wp_unregister_ability($ability_name);
    }
}

/**
 * Turn the MCP adapter's generic "ability not found" result into a clear "switched off" message
 * when the requested ability is one an admin disabled in the Abilities screen. A disabled ability is
 * unregistered, so the adapter cannot otherwise tell it apart from one that was never installed —
 * which leaves an agent following a skill that calls it with a misleading "not found".
 *
 * Filters mcp_adapter_tool_call_result (the execute-ability / get-ability-info result).
 *
 * @param mixed $result The raw tool result.
 * @param mixed $args   The tool arguments; carries ability_name for the ability meta-tools.
 * @return mixed
 */
// The adapter result has several independently guarded shapes and two distinct enriched error variants.
function novamira_enrich_disabled_ability_error(mixed $result, mixed $args): mixed
{
    if (!is_array($result) || ($result['success'] ?? null) !== false) {
        return $result;
    }

    $name = is_array($args) && is_string($args['ability_name'] ?? null) ? $args['ability_name'] : '';
    if ($name === '' || ($result['error'] ?? null) !== "Ability '{$name}' not found") {
        return $result;
    }

    $rules = novamira_get_ability_rules();
    $features = \Novamira\Features\features();
    $feature = $features->feature_for_ability($name);
    $feature_inactive = $feature !== null && !$features->is_active($feature->id);
    $standalone_disabled = $feature === null && ($rules[$name]['disabled'] ?? false) === true;
    if (!$standalone_disabled && !$feature_inactive) {
        return $result;
    }

    if ($feature_inactive) {
        $result['error'] = sprintf(
            /* translators: 1: ability name, 2: feature label */
            __(
                "Ability '%1\$s' is managed by the %2\$s feature, which is currently inactive. Ask the site admin to enable it under Novamira → Features, then retry.",
                domain: 'novamira',
            ),
            $name,
            $feature->label,
        );
        return $result;
    }

    $result['error'] = sprintf(
        /* translators: %s: the ability name, e.g. novamira/execute-php */
        __(
            "Ability '%s' exists but is switched off in Novamira's AI Abilities settings. Ask the site admin to re-enable it there, then retry.",
            domain: 'novamira',
        ),
        $name,
    );

    return $result;
}

/**
 * Handle the dismiss-production-warning form submission. Called from admin_init.
 */
function novamira_handle_dismiss_production_warning(): void
{
    if (($_POST['novamira_dismiss_production_warning'] ?? null) === null) {
        return;
    }

    if (!novamira_current_user_can_manage()) {
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
 * Permission callback for privileged Novamira administration.
 *
 * @return bool
 */
function novamira_permission_callback()
{
    if (!novamira_is_enabled()) {
        return false;
    }

    return novamira_current_user_can_manage();
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
 * Markdown lines that report the active theme and ask the user to choose a
 * working mode before content/layout work. Page builders and block libraries
 * are intentionally not hardcoded: the AI identifies them from the
 * installed-plugins inventory above, which stays correct as new ones ship.
 *
 * @return list<string>
 */
function novamira_build_building_context_lines(): array
{
    $theme = wp_get_theme();
    $theme_desc = $theme->get('Name');
    if ($theme->get_template() !== $theme->get_stylesheet()) {
        $parent = $theme->parent();
        $theme_desc .=
            ' (child theme of ' . ($parent instanceof WP_Theme ? $parent->get('Name') : $theme->get_template()) . ')';
    }

    $lines = [
        '## Building pages and layout',
        '',
        'Active theme: ' . $theme_desc . '.',
    ];
    /** @var mixed $filtered_lines */
    $filtered_lines = apply_filters('novamira_building_context_lines', $lines);
    if (is_array($filtered_lines)) {
        $lines = array_values(array_map(static fn(mixed $line): string => is_string($line)
            ? $line
            : '', $filtered_lines));
    }

    return array_values(array_merge($lines, [
        '',
        'Before building or restructuring a page\'s content or layout, check the installed-plugins inventory above for page builders (which replace the editor) and block libraries (which extend Gutenberg), then ask the user which approach to use: a page builder, Gutenberg, classic theme templates, a child theme, or a custom theme. Ask once and follow that choice; do not mix approaches (e.g. Gutenberg blocks in a page-builder page).',
    ]));
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
    $current_user = wp_get_current_user();
    $lines = [
        'Novamira gives you unrestricted control over this WordPress installation.',
        '',
        '## Connection safety',
        '',
        'IMPORTANT: Never update, replace, modify, deactivate, uninstall, or delete the Novamira plugin itself, because doing so would interrupt the connection you are currently using.',
    ];
    if ($current_user->ID > 0) {
        $lines[] =
            'You are connected through WordPress user ID '
            . $current_user->ID
            . '. Be careful when modifying this user or its authentication credentials: never revoke or delete its Novamira OAuth connections or Novamira WordPress Application Passwords, because doing so may interrupt the connection you are currently using.';
    }

    $lines = array_merge($lines, [
        '',
        '## Environment',
        '',
        'WordPress ' . get_bloginfo('version') . ' — PHP ' . PHP_VERSION . ' — Locale: ' . get_locale(),
    ]);

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

    $lines = array_merge($lines, novamira_build_building_context_lines());

    return implode("\n", $lines);
}

/**
 * Render the branded admin header with logo and background color.
 */
function novamira_render_admin_header(
    string $logo_file = 'novamira_logo.svg',
    string $logo_alt = 'Novamira',
    int $logo_width = 200,
    int $logo_height = 40,
): void { ?>
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
            <img src="<?php echo esc_url((string) NOVAMIRA_PLUGIN_URL . 'assets/' . $logo_file); ?>" alt="<?php echo
                esc_attr($logo_alt)
            ; ?>" width="<?php echo esc_attr((string) $logo_width); ?>" height="<?php echo
                esc_attr((string) $logo_height)
            ; ?>">
        </div>
    </div>
    <?php }
