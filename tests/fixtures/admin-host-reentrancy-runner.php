<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for AdminRedirectHostReentrancyTest:
 * php admin-host-reentrancy-runner.php <scenario>.
 *
 * Reproduces the chain core and a referer-validating `site_url` filter build around
 * allow_admin_host(), registered the way includes/oauth/bootstrap.php registers it:
 *
 *   admin_url() -> site_url filter -> wp_get_referer() -> wp_validate_redirect()
 *   -> allowed_redirect_hosts filter -> allow_admin_host() -> admin_url() -> ...
 *
 * Each scenario gets its own process, so the callback's guard state cannot leak between scenarios
 * and only what a scenario does within one request can leave it set. Nesting past MAX_DEPTH throws
 * instead of exhausting the stack, and the runner prints the outcome as JSON either way.
 */

const MAX_DEPTH = 25;

define('ABSPATH', '/');

final class FakeSite
{
    public static string $home_host = 'example.test';
    public static string $admin_host = 'admin.example.test';
    public static bool $admin_url_throws = false;
    public static int $admin_url_calls = 0;
    public static int $site_url_filter_calls = 0;
    public static int $depth = 0;

    /** @var array<string, list<callable>> */
    public static array $filters = [];

    /** @var list<mixed> Each allowed_redirect_hosts run's final list, in order of completion. */
    public static array $host_lists = [];

    /** @var list<mixed> What wp_get_referer() gave the site_url filter, in order of completion. */
    public static array $referers = [];
}

function apply_filters(string $hook, mixed $value): mixed
{
    foreach (FakeSite::$filters[$hook] ?? [] as $callback) {
        $value = $callback($value);
    }
    return $value;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function admin_url(string $path = ''): string
{
    FakeSite::$admin_url_calls++;
    if (FakeSite::$admin_url_throws) {
        throw new DomainException('admin_url failed');
    }

    FakeSite::$depth++;
    try {
        if (FakeSite::$depth > MAX_DEPTH) {
            throw new OverflowException('admin_url re-entered past ' . MAX_DEPTH . ' levels');
        }
        return apply_filters('site_url', 'https://' . FakeSite::$admin_host . '/wp-admin/' . $path);
    } finally {
        FakeSite::$depth--;
    }
}

/** Same seed and host check as core's wp_validate_redirect(). */
function wp_validate_redirect(string $location, mixed $fallback_url = ''): mixed
{
    $allowed_hosts = (array) apply_filters('allowed_redirect_hosts', [FakeSite::$home_host]);
    return in_array(parse_url($location, PHP_URL_HOST), $allowed_hosts, strict: true) ? $location : $fallback_url;
}

/** Same referer handling as core's wp_get_referer(), which validates any referer the request carries. */
function wp_get_referer(): mixed
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    return $referer !== '' ? wp_validate_redirect($referer, fallback_url: false) : false;
}

// A filter that consults the referer for every URL, before looking at what the URL is.
FakeSite::$filters['site_url'][] = static function (string $url): string {
    FakeSite::$site_url_filter_calls++;
    FakeSite::$referers[] = wp_get_referer();
    return $url;
};

require_once __DIR__ . '/../../includes/oauth/endpoints/authorize.php';

FakeSite::$filters['allowed_redirect_hosts'][] = 'Novamira\\OAuth\\Endpoints\\Authorize\\allow_admin_host';
FakeSite::$filters['allowed_redirect_hosts'][] = static function (mixed $hosts): mixed {
    FakeSite::$host_lists[] = $hosts;
    return $hosts;
};

$_SERVER['HTTP_HOST'] = 'attacker.test';
$_SERVER['HTTP_REFERER'] = 'https://example.test/linking-page/';

$scenarios = [
    // A redirect to the admin host: the validation the callback exists for.
    'validate_admin_redirect' => static function (): array {
        $location = 'https://admin.example.test/wp-admin/admin.php?page=novamira-oauth-consent';
        return ['validated' => wp_validate_redirect($location, fallback_url: 'fallback')];
    },
    // Any admin_url() call in the request, with a referer that only the admin host entry allows.
    'admin_url_with_admin_referer' => static function (): array {
        $_SERVER['HTTP_REFERER'] = 'https://admin.example.test/wp-admin/edit.php';
        return ['url' => admin_url('admin.php')];
    },
    'foreign_referer' => static function (): array {
        $_SERVER['HTTP_REFERER'] = 'https://attacker.test/page/';
        return ['url' => admin_url('admin.php')];
    },
    'hosts_match' => static function (): array {
        FakeSite::$admin_host = 'example.test';
        return ['validated' => wp_validate_redirect('https://example.test/page/', fallback_url: 'fallback')];
    },
    // switch_to_blog() between two validations of one request changes the admin host.
    'admin_host_changes_between_calls' => static function (): array {
        $first = apply_filters('allowed_redirect_hosts', ['example.test']);
        FakeSite::$admin_host = 'other-admin.example.test';
        $second = apply_filters('allowed_redirect_hosts', ['example.test']);
        return ['first' => $first, 'second' => $second];
    },
    'admin_url_throws_then_recovers' => static function (): array {
        FakeSite::$admin_url_throws = true;
        $thrown = null;
        try {
            apply_filters('allowed_redirect_hosts', ['example.test']);
        } catch (DomainException $exception) {
            $thrown = $exception->getMessage();
        }
        FakeSite::$admin_url_throws = false;
        return ['thrown' => $thrown, 'after' => apply_filters('allowed_redirect_hosts', ['example.test'])];
    },
];

$scenario = $scenarios[$argv[1] ?? ''] ?? null;
if ($scenario === null) {
    exit(2);
}

try {
    $result = $scenario();
} catch (Throwable $throwable) {
    $result = ['error' => get_class($throwable) . ': ' . $throwable->getMessage()];
}

echo json_encode($result + [
    'admin_url_calls' => FakeSite::$admin_url_calls,
    'site_url_filter_calls' => FakeSite::$site_url_filter_calls,
    'host_lists' => FakeSite::$host_lists,
    'referers' => FakeSite::$referers,
]);
