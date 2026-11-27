<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

define('ABSPATH', '/');
define('AUTH_KEY', 'site-auth-key-value-123');
define('DB_PASSWORD', 'database-password-456');
$GLOBALS['options'] = [];

class WP_Error
{
    private array $errors = [];
    private array $data = [];
    public function add(int|string $code, string $message, mixed $data = ''): void
    {
        $this->errors[$code][] = $message;
        if ($data !== '') $this->data[$code] = $data;
    }
    public function get_error_codes(): array { return array_keys($this->errors); }
    public function get_error_messages(int|string $code = ''): array { return $this->errors[$code] ?? []; }
    public function get_error_data(int|string $code = ''): mixed { return $this->data[$code] ?? null; }
}
class WP_REST_Request
{
    public function __construct(private string $route) {}
    public function get_route(): string { return $this->route; }
}
class WP_REST_Response
{
    public function __construct(private mixed $data) {}
    public function get_data(): mixed { return $this->data; }
    public function set_data(mixed $data): void { $this->data = $data; }
}
function add_filter(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): void {}
function add_action(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): void {}
function get_option(string $name, mixed $default_value = false): mixed
{
    return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default_value;
}
function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

require $argv[1] . '/includes/secrets/bootstrap.php';

use Novamira\Secrets as S;

S\remember_password('Gen3rated!Pass#xy');

/** Everything that reaches the AI, through each place that hides secrets. */
function reaches_the_ai(): array
{
    $error = new WP_Error();
    $error->add('failed', 'Could not connect with database-password-456', ['status' => 500]);
    $redacted_error = S\redact_tool_result($error);
    return [
        'enabled' => S\enabled(),
        'mcp' => S\redact_tool_result([
            'content' => [['type' => 'text', 'text' => "define('AUTH_KEY', 'site-auth-key-value-123');"]],
            'structuredContent' => ['hash' => '$P$Babcdefghijklmnopqrstuvwxyz1234', 'password' => 'Gen3rated!Pass#xy'],
        ]),
        'mcp_error' => $redacted_error instanceof WP_Error
            ? [$redacted_error->get_error_messages('failed'), $redacted_error->get_error_data('failed')]
            : null,
        'mcp_error_is_same' => $redacted_error === $error,
        'rest_wp_abilities' => S\redact_ability_run_response(
            new WP_REST_Response(['output' => 'db database-password-456']),
            null,
            new WP_REST_Request('/wp-abilities/v1/abilities/novamira/execute-php/run'),
        )->get_data(),
        'rest_novamira' => S\redact_ability_run_response(
            new WP_REST_Response(['output' => 'db database-password-456']),
            null,
            new WP_REST_Request('/novamira/v1/abilities/novamira/execute-php/run'),
        )->get_data(),
        'chat_and_log' => S\hide(['result' => 'key site-auth-key-value-123', 'nested' => ['Gen3rated!Pass#xy']]),
        'text' => S\hide_string('key site-auth-key-value-123'),
        'env_file' => S\hide_file_text('/srv/site/.env', "APP_KEY=abc123\nDB=database-password-456\n"),
        'php_file' => S\hide_file_text('/srv/site/wp-config.php', "define('AUTH_KEY', 'site-auth-key-value-123');"),
    ];
}

$out = [];
$out['default'] = reaches_the_ai();
$GLOBALS['options']['novamira_secret_redaction'] = S\sanitize_setting('0');
$out['off'] = reaches_the_ai();
$GLOBALS['options']['novamira_secret_redaction'] = S\sanitize_setting('1');
$out['back_on'] = reaches_the_ai();
$out['sanitized'] = array_map(S\sanitize_setting(...), ['0', 0, false, '1', 1, true, 'off', '', null]);
// A value stored by hand other than '0' keeps secrets hidden.
$GLOBALS['options']['novamira_secret_redaction'] = 'no';
$out['unknown_value'] = S\enabled();

echo json_encode($out);
