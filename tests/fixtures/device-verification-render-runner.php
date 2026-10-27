<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for DeviceVerificationRenderTest:
 * php device-verification-render-runner.php <device_result> [user_code].
 *
 * Renders the verification page as a GET with the given `device_result`, or as the page's own
 * lookup POST whenever a user code argument is passed, including an empty one. The nonce actions
 * checked are printed after the page, one `nonce: <action>` line each.
 */

define('ABSPATH', '/');
define('ARRAY_A', 'ARRAY_A');

$device_result = $argv[1] ?? '';

if ($device_result !== '') {
    $_GET['device_result'] = $device_result;
}
$_SERVER['REQUEST_METHOD'] = 'GET';
if ($argc > 2) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['novamira_action' => 'lookup', 'user_code' => $argv[2]];
}
$GLOBALS['checked_nonces'] = [];

function is_user_logged_in(): bool
{
    return true;
}

function novamira_current_user_can_manage(): bool
{
    return true;
}

function novamira_render_admin_header(): void {}

function check_admin_referer(string $action): int
{
    $GLOBALS['checked_nonces'][] = $action;
    return 1;
}

function wp_nonce_field(string $action): string
{
    echo '<input type="hidden" name="_wpnonce" value="nonce">';
    return '';
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_attr__(string $text, string $domain = 'default'): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_url(string $url): string
{
    return $url;
}

function sanitize_text_field(string $text): string
{
    return trim($text);
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}

/** No authorization exists, so a looked-up code is always the "not valid" path. */
final class wpdb
{
    public string $prefix = 'wp_';

    public function prepare(string $query, mixed ...$args): string
    {
        return $query;
    }

    public function get_row(string $query, string $output = 'OBJECT'): mixed
    {
        return null;
    }
}

$GLOBALS['wpdb'] = new wpdb();

require __DIR__ . '/../../includes/oauth/repositories/device-code-repository.php';
require __DIR__ . '/../../includes/oauth/endpoints/device.php';
require __DIR__ . '/../../includes/oauth/device-verification.php';

Novamira\OAuth\DeviceVerification\render();

foreach ($GLOBALS['checked_nonces'] as $action) {
    echo "\nnonce: {$action}";
}
