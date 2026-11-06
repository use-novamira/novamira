<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Loaded from setUp() of isolated tests only, so the suite process never declares these stubs and
// NOVAMIRA_WP_CLI_COMMAND stays undefined unless a test defines it.

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86_400);
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return $value;
}

// NOVAMIRA_TEST_TRANSIENT_FILE backs the store with a file, so separate PHP processes - separate
// requests - share it the way requests share the options table.
function novamira_test_transient_file(): ?string
{
    $file = getenv('NOVAMIRA_TEST_TRANSIENT_FILE');

    return is_string($file) && $file !== '' ? $file : null;
}

function get_transient(string $name): mixed
{
    $GLOBALS['novamira_test_transient_reads'][] = $name;

    $file = novamira_test_transient_file();
    if ($file !== null && is_file($file)) {
        $GLOBALS['novamira_test_transients'] = unserialize((string) file_get_contents($file));
    }

    return $GLOBALS['novamira_test_transients'][$name]['value'] ?? false;
}

function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    $GLOBALS['novamira_test_transients'][$name] = ['value' => $value, 'expiration' => $expiration];

    $file = novamira_test_transient_file();
    if ($file !== null) {
        file_put_contents($file, serialize($GLOBALS['novamira_test_transients']));
    }

    return true;
}

if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
        ) {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
