<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/');
    }
    if (!function_exists('add_action')) {
        function add_action(
            string $hook_name,
            callable|string $callback,
            int $priority = 10,
            int $accepted_args = 1,
        ): bool {
            $GLOBALS['novamira_test_actions'][] = [$hook_name, $callback, $priority, $accepted_args];
            return true;
        }
    }
    if (!function_exists('get_option')) {
        function get_option(string $name, mixed $default_value = false): mixed
        {
            return $GLOBALS['novamira_test_options'][$name] ?? $default_value;
        }
    }
    if (!function_exists('is_multisite')) {
        function is_multisite(): bool
        {
            return (bool) ($GLOBALS['novamira_test_is_multisite'] ?? false);
        }
    }
    if (!function_exists('get_blog_option')) {
        function get_blog_option(?int $id, string $name, mixed $default_value = false): mixed
        {
            return $GLOBALS['novamira_test_blog_options'][$name] ?? $default_value;
        }
    }

    if (!class_exists('WP_Rewrite')) {
        /**
         * Minimal WP_Rewrite stub. Reads permalink_structure from a test global instead of
         * get_option(). The using_index_permalinks() regex matches core's implementation.
         * Not modelled: using_permalinks(), the full init() lifecycle, rewrite rules, or
         * any state beyond permalink_structure and index.
         */
        class WP_Rewrite
        {
            public string $index = 'index.php';
            public string $permalink_structure;

            public function __construct()
            {
                $this->permalink_structure = (string) ($GLOBALS['novamira_test_permalink_structure'] ?? '/%postname%/');
            }

            public function using_index_permalinks(): bool
            {
                if ($this->permalink_structure === '') {
                    return false;
                }

                return (bool) preg_match('#^/*' . $this->index . '#', $this->permalink_structure);
            }
        }
    }
}

namespace Novamira\OAuth {
    if (!function_exists('Novamira\\OAuth\\rest_url')) {
        /**
         * Namespaced rest_url() that delegates to the global \rest_url() by default, keeping
         * pre-existing tests on their constant stub. When a test sets
         * $GLOBALS['novamira_test_realistic_rest_url'] = true, this exercises the
         * $wp_rewrite->using_index_permalinks() dereference from the non-empty
         * permalink_structure branch of core's get_rest_url() — the code path that fatals
         * when $wp_rewrite is null.
         *
         * Not modelled: multisite get_blog_option(), the rest_url_prefix / home_url / rest_url
         * filters, home subdirectories via get_home_url(), SSL/admin scheme handling, the
         * plain-permalink branch (which inserts index.php and uses add_query_arg), and the
         * is_admin() gate. Tests only cover non-empty permalink_structure cases.
         */
        function rest_url(string $path = ''): string
        {
            if (!($GLOBALS['novamira_test_realistic_rest_url'] ?? false)) {
                return function_exists('rest_url') ? \rest_url($path) : ('https://example.test/wp-json/' . ltrim($path, '/'));
            }

            $structure = (string) ($GLOBALS['novamira_test_permalink_structure'] ?? '/%postname%/');
            $home = rtrim((string) ($GLOBALS['novamira_test_home'] ?? 'https://example.test'), '/');

            if ($structure !== '') {
                if ($GLOBALS['wp_rewrite']->using_index_permalinks()) {
                    return $home . '/' . $GLOBALS['wp_rewrite']->index . '/wp-json/' . ltrim($path, '/');
                }

                return $home . '/wp-json/' . ltrim($path, '/');
            }

            return function_exists('rest_url') ? \rest_url($path) : ($home . '/wp-json/' . ltrim($path, '/'));
        }
    }
}
