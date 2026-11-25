<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

define('ABSPATH', '/');
define('AUTH_KEY', 'site-auth-key-value-123');
define('DB_PASSWORD', 'database-password-456');
$GLOBALS['filters'] = [];
$GLOBALS['options'] = ['novamira_oauth_private_key' => 'oauth-private-key-value'];

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
function add_filter(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['filters'][$hook] = $callback;
}
function add_action(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): void
{
    $GLOBALS['actions'][] = $hook;
}
function get_option(string $name, mixed $default_value = false): mixed
{
    return $GLOBALS['options'][$name] ?? $default_value;
}
function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

require $argv[1] . '/includes/secrets/bootstrap.php';

use Novamira\Secrets as S;

$out = [];
$out['hooks'] = array_keys($GLOBALS['filters']);
$out['actions'] = $GLOBALS['actions'];
$out['tool_result'] = S\redact_tool_result([
    'content' => [['type' => 'text', 'text' => "define('AUTH_KEY', 'site-auth-key-value-123'); db=database-password-456"]],
    'structuredContent' => ['hash' => '$P$Babcdefghijklmnopqrstuvwxyz1234', 'note' => 'ok'],
]);
$error = new WP_Error();
$error->add('failed', 'Could not connect with database-password-456', ['status' => 500]);
$redacted_error = S\redact_tool_result($error);
$out['error_redacted'] = $redacted_error instanceof WP_Error
    ? [$redacted_error->get_error_messages('failed'), $redacted_error->get_error_data('failed')]
    : null;
$run = S\redact_ability_run_response(
    new WP_REST_Response(['output' => 'key oauth-private-key-value']),
    null,
    new WP_REST_Request('/wp-abilities/v1/abilities/novamira/execute-php/run'),
);
$out['rest_run'] = $run->get_data();
// WordPress matches routes ignoring case: secrets stay hidden however the address is written.
$out['rest_run_any_case'] = S\redact_ability_run_response(
    new WP_REST_Response(['output' => 'key oauth-private-key-value']),
    null,
    new WP_REST_Request('/NOVAMIRA/V1/abilities/novamira/execute-php/RUN'),
)->get_data();
$other = S\redact_ability_run_response(
    new WP_REST_Response(['output' => 'key oauth-private-key-value']),
    null,
    new WP_REST_Request('/wp/v2/posts'),
);
$out['rest_other'] = $other->get_data();
$out['env_files'] = [
    S\is_env_file('/srv/site/.env'),
    S\is_env_file('/srv/site/.env.production'),
    S\is_env_file('/srv/site/prod.env'),
    S\is_env_file('/srv/site/environment.php'),
];
$out['env'] = S\redact_env("# comment\nAPP_KEY=abc123\nexport STRIPE_SECRET = \"sk\"\nEMPTY=\n");

// Passwords set while the AI works: by wp_insert_user() for a new user, wp_set_password() or an update.
$out['before_set'] = S\redact(['password' => 'Gen3rated!Pass#xy']);
S\remember_new_user_password(5, ['user_login' => 'mario', 'user_pass' => 'Gen3rated!Pass#xy']);
S\remember_password('A/b"c-d9!e');
S\remember_password('short1');
S\remember_updated_user_password(5, null, ['user_pass' => '$P$Babcdefghijklmnopqrstuvwxyz1234']);
$out['set_passwords'] = S\redact_tool_result([
    'content' => [['type' => 'text', 'text' => '{"password":"Gen3rated!Pass#xy","other":"A\\/b\\"c-d9!e"}']],
    'structuredContent' => [
        'password' => 'Gen3rated!Pass#xy',
        'other' => 'A/b"c-d9!e',
        'short' => 'short1',
        'hash' => '$P$Babcdefghijklmnopqrstuvwxyz1234',
    ],
]);

echo json_encode($out);
