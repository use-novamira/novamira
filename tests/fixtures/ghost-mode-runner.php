<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Usage: php ghost-mode-runner.php <plugin-root> <scenario> <world-json>

namespace {
    if ($argc !== 4) {
        fwrite(STDERR, "Usage: php ghost-mode-runner.php <plugin-root> <scenario> <world-json>\n");
        exit(2);
    }

    $world = json_decode($argv[3], associative: true);
    if (!is_array($world)) {
        fwrite(STDERR, "World is not valid JSON.\n");
        exit(2);
    }
    $GLOBALS['gm_world'] = $world + [
        'user' => 0,
        'users' => [],
        'multisite' => false,
        'init' => true,
        'bypass' => false,
        'constants' => [],
        'pagenow' => 'index.php',
        'request' => [],
        'ajax' => false,
        'cron' => false,
        'pro' => false,
        'args' => [],
    ];
    $GLOBALS['gm_options'] = array_key_exists('option', $world) ? ['novamira_ghost_mode' => $world['option']] : [];
    $GLOBALS['gm_hooks'] = [];
    $GLOBALS['gm_calls'] = [];
    $GLOBALS['pagenow'] = $GLOBALS['gm_world']['pagenow'];
    $_GET = $GLOBALS['gm_world']['request']['get'] ?? [];
    $_POST = $GLOBALS['gm_world']['request']['post'] ?? [];
    $_REQUEST = array_merge($_GET, $_POST);

    define('ABSPATH', '/');
    define('NOVAMIRA_PLUGIN_URL', 'https://example.test/wp-content/plugins/novamira/');
    define('NOVAMIRA_VERSION', 'test');
    if ($GLOBALS['gm_world']['bypass'] === true) {
        define('NOVAMIRA_GHOST_MODE_BYPASS', true);
    }
    foreach ($GLOBALS['gm_world']['constants'] as $name => $value) {
        define($name, $value);
    }

    final class GmDied extends RuntimeException
    {
        public function __construct(string $message, public int $status)
        {
            parent::__construct($message);
        }
    }

    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '')
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }

    final class WP_CLI
    {
        /** @var list<array{0: string, 1: string}> */
        public static array $out = [];
        /** @var list<string> */
        public static array $commands = [];

        public static function add_command(string $name, mixed $callable): void
        {
            self::$commands[] = $name;
        }

        public static function line(string $message = ''): void
        {
            self::$out[] = ['line', $message];
        }

        public static function success(string $message): void
        {
            self::$out[] = ['success', $message];
        }

        public static function warning(string $message): void
        {
            self::$out[] = ['warning', $message];
        }

        public static function error(string $message): void
        {
            self::$out[] = ['error', $message];
            throw new GmDied($message, 1);
        }

        public static function confirm(string $question, array $assoc_args = []): void
        {
            if (!array_key_exists('yes', $assoc_args)) {
                self::$out[] = ['confirm', $question];
                throw new GmDied('not confirmed', 1);
            }
        }
    }

    class WP_User
    {
        public string $user_login;
        public string $display_name;

        public function __construct(public int $ID)
        {
            $this->user_login = (string) ($GLOBALS['gm_world']['users'][(string) $ID]['login'] ?? 'user' . $ID);
            $this->display_name = $this->user_login;
        }
    }

    function gm_user_manages(int $user_id): bool
    {
        return ($GLOBALS['gm_world']['users'][(string) $user_id]['manage'] ?? false) === true;
    }

    function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['gm_hooks'][] = [$hook, is_string($callback) ? $callback : 'closure', $priority];
        return true;
    }

    function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return add_action($hook, $callback, $priority, $accepted_args);
    }

    function has_action(string $hook, mixed $callback = false): bool
    {
        foreach ($GLOBALS['gm_hooks'] as [$registered]) {
            if ($registered === $hook) {
                return true;
            }
        }
        return false;
    }

    function do_action(string $hook, mixed ...$args): void
    {
        $GLOBALS['gm_calls'][] = ['do_action', $hook];
    }

    function did_action(string $hook): int
    {
        return $hook === 'init' && $GLOBALS['gm_world']['init'] === true ? 1 : 0;
    }

    function get_site_option(string $option, mixed $default_value = false): mixed
    {
        return array_key_exists($option, $GLOBALS['gm_options']) ? $GLOBALS['gm_options'][$option] : $default_value;
    }

    function update_site_option(string $option, mixed $value): bool
    {
        $GLOBALS['gm_options'][$option] = $value;
        return true;
    }

    function delete_site_option(string $option): bool
    {
        unset($GLOBALS['gm_options'][$option]);
        return true;
    }

    function is_multisite(): bool
    {
        return $GLOBALS['gm_world']['multisite'] === true;
    }

    function get_current_user_id(): int
    {
        return (int) $GLOBALS['gm_world']['user'];
    }

    function is_super_admin(int|false $user_id = false): bool
    {
        return gm_user_manages($user_id === false ? get_current_user_id() : $user_id);
    }

    function current_user_can(string $capability, mixed ...$args): bool
    {
        return gm_user_manages(get_current_user_id());
    }

    function user_can(int|WP_User $user, string $capability): bool
    {
        return gm_user_manages($user instanceof WP_User ? $user->ID : $user);
    }

    function get_userdata(int $user_id): WP_User|false
    {
        return array_key_exists((string) $user_id, $GLOBALS['gm_world']['users']) ? new WP_User($user_id) : false;
    }

    function get_user_by(string $field, int|string $value): WP_User|false
    {
        foreach ($GLOBALS['gm_world']['users'] as $id => $user) {
            if ($field === 'id' && (int) $id === (int) $value) {
                return new WP_User((int) $id);
            }
            if (in_array($field, ['login', 'email'], strict: true) && ($user['login'] ?? '') === $value) {
                return new WP_User((int) $id);
            }
        }
        return false;
    }

    function novamira_current_user_can_manage(): bool
    {
        return is_multisite() ? is_super_admin() : current_user_can('manage_options');
    }

    function novamira_user_can_manage(int|WP_User $user): bool
    {
        $user_id = $user instanceof WP_User ? $user->ID : $user;
        return $user_id > 0 && (is_multisite() ? is_super_admin($user_id) : user_can($user, 'manage_options'));
    }

    function novamira_manage_capability(): string
    {
        return 'manage_options';
    }

    function novamira_pro_is_active(): bool
    {
        return $GLOBALS['gm_world']['pro'] === true;
    }

    function wp_die(mixed $message = '', mixed $title = '', array $args = []): void
    {
        throw new GmDied((string) $message, (int) ($args['response'] ?? 500));
    }

    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }

    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
    }

    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo $text;
    }

    function esc_html(string $text): string
    {
        return $text;
    }

    function esc_attr(string $text): string
    {
        return $text;
    }

    function esc_url(string $url): string
    {
        return $url;
    }

    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }

    define('NOVAMIRA_PRO_URL', 'https://novamira.ai/pro/');

    function novamira_render_admin_header(): void
    {
        echo '[header]';
    }

    function wp_nonce_field(string $action): void
    {
        echo '[nonce:' . $action . ']';
    }

    function submit_button(?string $text = null, string $type = 'primary', string $name = 'submit', bool $wrap = true): void
    {
        echo '[button:' . (string) $text . ']';
    }

    function check_admin_referer(string $action): int
    {
        if (($_REQUEST['_wpnonce'] ?? '') !== $action) {
            throw new GmDied('bad nonce', 403);
        }
        return 1;
    }

    function wp_safe_redirect(string $location): void
    {
        $GLOBALS['gm_calls'][] = ['redirect', $location];
    }

    function add_submenu_page(string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = ''): string
    {
        $GLOBALS['gm_calls'][] = ['submenu', $parent_slug, $menu_slug];
        return 'novamira_page_' . $menu_slug;
    }

    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . $path;
    }

    function network_admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/network/' . $path;
    }

    function add_query_arg(mixed ...$args): string
    {
        foreach (array_keys($args) as $position) {
            if (is_string($position)) {
                return '';
            }
        }
        if (is_array($args[0] ?? null)) {
            $pairs = $args[0];
            $url = (string) ($args[1] ?? '');
        } else {
            $pairs = [(string) ($args[0] ?? '') => $args[1] ?? ''];
            $url = (string) ($args[2] ?? '');
        }
        foreach ($pairs as $key => $value) {
            $url .= (str_contains($url, '?') ? '&' : '?') . $key . '=' . (string) $value;
        }
        return $url;
    }

    function wp_doing_ajax(): bool
    {
        return $GLOBALS['gm_world']['ajax'] === true;
    }

    function wp_doing_cron(): bool
    {
        return $GLOBALS['gm_world']['cron'] === true;
    }

    function plugin_basename(string $file): string
    {
        return basename(dirname($file)) . '/' . basename($file);
    }

    function wp_date(string $format, int $timestamp): string
    {
        return gmdate('Y-m-d H:i', $timestamp);
    }

    function wp_admin_notice(string $message, array $args = []): void
    {
        echo '[' . ($args['type'] ?? 'info') . '] ' . $message;
    }

    function get_current_screen(): ?object
    {
        return isset($GLOBALS['gm_world']['screen']) ? (object) ['id' => $GLOBALS['gm_world']['screen']] : null;
    }

    function get_site_transient(string $transient): mixed
    {
        return $transient === 'update_plugins' && isset($GLOBALS['gm_world']['updates'])
            ? (object) ['response' => array_fill_keys($GLOBALS['gm_world']['updates'], (object) [])]
            : false;
    }

    function is_network_admin(): bool
    {
        return false;
    }

    final class GmWpdb
    {
        public string $prefix = 'wp_';

        public function prepare(string $query, mixed ...$args): string
        {
            return $query;
        }

        /** @return list<array<string, mixed>> */
        public function get_results(string $query, string $output = 'OBJECT'): array
        {
            return $GLOBALS['gm_world']['oauth_rows'] ?? [];
        }
    }
    $GLOBALS['wpdb'] = new GmWpdb();

    final class WP_Application_Passwords
    {
        public const USERMETA_KEY_APPLICATION_PASSWORDS = '_application_passwords';

        /** @return list<array<string, mixed>> */
        public static function get_user_application_passwords(int $user_id): array
        {
            return $GLOBALS['gm_world']['app_passwords'][(string) $user_id] ?? [];
        }
    }

    function novamira_application_password_user_ids(): array
    {
        return array_map('intval', array_keys($GLOBALS['gm_world']['app_passwords'] ?? []));
    }

    function novamira_is_application_password(array $password): bool
    {
        return str_starts_with((string) ($password['name'] ?? ''), 'Novamira');
    }

    define('ARRAY_A', 'ARRAY_A');

    $scenario = $argv[2];
    $root = $argv[1];

    function remove_menu_page(string $menu_slug): void
    {
        foreach ($GLOBALS['menu'] as $index => $item) {
            if (($item[2] ?? null) === $menu_slug) {
                unset($GLOBALS['menu'][$index]);
            }
        }
    }

    require $root . '/includes/ghost-mode/policy.php';
    require $root . '/includes/ghost-mode/visibility.php';
    require $root . '/includes/ghost-mode/api.php';
    require $root . '/includes/ghost-mode/enforcement.php';
    require $root . '/includes/ghost-mode/notices.php';
    require $root . '/includes/ghost-mode/page.php';
    require $root . '/includes/ghost-mode/connections.php';
    require $root . '/includes/ghost-mode/cli.php';

    /** @var array<string, Closure(array): mixed> $scenarios */
    $scenarios = [];

    $scenarios['normalize'] = static fn(array $args): mixed => \Novamira\GhostMode\normalize($args['raw'] ?? false);

    $scenarios['save'] = static function (array $args): mixed {
        $error = \Novamira\GhostMode\save_policy(
            (bool) $args['enabled'],
            (array) $args['visible_to'],
            (int) $args['user_id'],
            (string) $args['source'],
            (string) ($args['pro_version'] ?? ''),
        );
        return ['error' => $error?->get_error_code(), 'option' => get_site_option('novamira_ghost_mode')];
    };

    $scenarios['disable'] = static function (array $args): mixed {
        $error = \Novamira\GhostMode\disable((int) $args['user_id'], (string) $args['source']);
        return ['error' => $error?->get_error_code(), 'option' => get_site_option('novamira_ghost_mode')];
    };

    $scenarios['add_owner'] = static function (array $args): mixed {
        $error = \Novamira\GhostMode\add_owner((int) $args['owner_id'], (int) $args['user_id'], (string) $args['source']);
        return ['error' => $error?->get_error_code(), 'option' => get_site_option('novamira_ghost_mode')];
    };

    $scenarios['visible'] = static fn(array $args): mixed => [
        'visible' => novamira_admin_ui_visible(),
        'hidden_by_policy' => \Novamira\GhostMode\hidden_by_policy(),
    ];

    $scenarios['status'] = static fn(array $args): mixed => \Novamira\GhostMode\status();

    $scenarios['menus'] = static function (array $args): mixed {
        $GLOBALS['menu'] = $args['menu'];
        $GLOBALS['submenu'] = $args['submenu'];
        \Novamira\GhostMode\hide_menus();
        return ['menu' => array_values($GLOBALS['menu']), 'submenu' => $GLOBALS['submenu']];
    };

    $scenarios['guard'] = static function (array $args): mixed {
        \Novamira\GhostMode\guard_request();
        return 'passed';
    };

    $scenarios['plugins'] = static fn(array $args): mixed => array_keys(
        \Novamira\GhostMode\filter_plugin_list($args['plugins']),
    );

    $scenarios['plugins_raw'] = static fn(array $args): mixed => \Novamira\GhostMode\filter_plugin_list(
        $args['plugins'],
    );

    $scenarios['transient'] = static function (array $args): mixed {
        $transient = (object) ['response' => $args['response'], 'no_update' => $args['no_update'], 'checked' => []];
        if (($args['suspend'] ?? false) === true) {
            \Novamira\GhostMode\suspend_update_projection();
        }
        $projected = \Novamira\GhostMode\project_update_transient($transient);
        return [
            'response' => array_keys((array) $projected->response),
            'no_update' => array_keys((array) $projected->no_update),
            'original_response' => array_keys((array) $transient->response),
        ];
    };

    $scenarios['bypass_notice'] = static function (array $args): mixed {
        \Novamira\GhostMode\render_bypass_notice();
        return null;
    };

    $scenarios['page'] = static function (array $args): mixed {
        if (($args['panel'] ?? false) === true) {
            add_action(\Novamira\GhostMode\PANEL_HOOK, 'pro_panel');
        }
        \Novamira\GhostMode\render_page();
        return null;
    };

    $scenarios['disable_post'] = static function (array $args): mixed {
        register_shutdown_function(static function (): void {
            if (($GLOBALS['gm_printed'] ?? false) === true) {
                return;
            }
            echo json_encode([
                'result' => 'exited',
                'option' => get_site_option('novamira_ghost_mode'),
                'calls' => $GLOBALS['gm_calls'],
                'output' => '',
            ]);
        });
        \Novamira\GhostMode\handle_disable();
        return 'no-exit';
    };

    $scenarios['connected_users'] = static fn(array $args): mixed => \Novamira\GhostMode\connected_users();

    $scenarios['cli'] = static function (array $args): mixed {
        \Novamira\GhostMode\Cli\register();
        try {
            match ($args['command']) {
                'status' => \Novamira\GhostMode\Cli\status_command(),
                'disable' => \Novamira\GhostMode\Cli\disable_command([], $args['assoc'] ?? []),
                'owners add' => \Novamira\GhostMode\Cli\owners_add_command([(string) $args['user']]),
            };
        } catch (GmDied) {
        }
        return ['out' => WP_CLI::$out, 'commands' => WP_CLI::$commands, 'option' => get_site_option('novamira_ghost_mode')];
    };

    // Later tasks add scenarios above this line.

    if (!array_key_exists($scenario, $scenarios)) {
        fwrite(STDERR, "Unknown scenario {$scenario}\n");
        exit(2);
    }

    ob_start();
    try {
        $result = ['result' => $scenarios[$scenario]($GLOBALS['gm_world']['args'])];
    } catch (GmDied $died) {
        $result = ['died' => $died->status, 'message' => $died->getMessage()];
    }
    $result['output'] = (string) ob_get_clean();
    $result['calls'] = $GLOBALS['gm_calls'];
    $GLOBALS['gm_printed'] = true;
    echo json_encode($result);
}

namespace Novamira\OAuth\Connections {
    function oauth_storage_available(): bool
    {
        return ($GLOBALS['gm_world']['oauth'] ?? true) === true;
    }
}
