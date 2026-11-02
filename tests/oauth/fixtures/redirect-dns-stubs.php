<?php

/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Novamira\OAuth\ClientValidation {
    function gethostbynamel(string $host): array|false
    {
        $GLOBALS['nm_dns_queries'][] = ['A', $host];
        return $GLOBALS['nm_dns_a'];
    }

    function dns_get_record(string $host, int $type): array|false
    {
        $GLOBALS['nm_dns_queries'][] = ['AAAA', $host];
        return $GLOBALS['nm_dns_aaaa'];
    }
}

namespace {
    final class RedirectDnsResponse extends \RuntimeException
    {
        public function __construct(public string $kind, string $message, int $status)
        {
            parent::__construct($message, $status);
        }
    }

    function wp_die(string $message, string $title = '', array $args = []): never
    {
        throw new RedirectDnsResponse('error', $message, $args['response'] ?? 500);
    }

    function wp_redirect(string $location, int $status = 302): never
    {
        throw new RedirectDnsResponse('redirect', $location, $status);
    }

    function wp_safe_redirect(string $location, int $status = 302): never
    {
        wp_redirect($location, $status);
    }

    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }

    function novamira_current_user_can_manage(): bool
    {
        return true;
    }

    function get_current_user_id(): int
    {
        return 73;
    }

    function is_multisite(): bool
    {
        return false;
    }

    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }

    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . $path;
    }

    function check_admin_referer(string $action): int
    {
        $GLOBALS['nm_nonce_checks'][] = $action;
        return 1;
    }

    function add_query_arg(array $args, string $url): string
    {
        return $url . '?' . http_build_query($args);
    }
}
