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

// Synthetic PEM data includes slashes and a short final line, as LEFT(option_value, 1500) can return.
$body = str_repeat(str_repeat('Ab9/', 16) . "\n", 25) . 'Tail9/';
$pem = "-----BEGIN RSA PRIVATE KEY-----\n" . $body . "\n-----END RSA PRIVATE KEY-----";
$GLOBALS['options']['novamira_oauth_private_key'] = $pem;
$GLOBALS['options']['novamira_oauth_encryption_key'] = str_repeat('e', 44);
$prefix = substr($pem, 0, 1500);
$unknown = str_replace('RSA PRIVATE', 'EC PRIVATE', $prefix);
$short_lines = "\nactive\nyes\n42\nDone\nnext line here";
$three_lines = "-----BEGIN RSA PRIVATE KEY-----\n" . str_repeat(str_repeat('Ab9/', 16) . "\n", 3) . 'Tail';
$forms = [
    'raw' => [$prefix, '[redacted]'],
    'json' => [json_encode($prefix), json_encode('[redacted]')],
    'short_final_line' => [substr($pem, 0, strpos($pem, "\n-----END")), '[redacted]'],
    'mixed_json' => [
        json_encode(['key' => $prefix, 'encryption' => str_repeat('e', 44)]),
        json_encode(['key' => '[redacted]', 'encryption' => '[redacted]']),
    ],
    'json_unescaped_slashes' => [json_encode($prefix, JSON_UNESCAPED_SLASHES), json_encode('[redacted]')],
    'print_r' => [
        print_r(['key' => $prefix, 'note' => 'ordinary text'], true),
        print_r(['key' => '[redacted]', 'note' => 'ordinary text'], true),
    ],
    'var_export' => [
        var_export(['key' => $prefix, 'note' => 'ordinary text'], true),
        var_export(['key' => '[redacted]', 'note' => 'ordinary text'], true),
    ],
    'adjacent_text' => ["before: " . $prefix . "\nordinary text after", "before: [redacted]\nordinary text after"],
    'short_words_raw' => [$three_lines . $short_lines, '[redacted]' . $short_lines],
    'short_words_json' => [json_encode($three_lines . $short_lines), json_encode('[redacted]' . $short_lines)],
    'same_line_text' => [$prefix . ' ordinary text after', '[redacted] ordinary text after'],
    'unknown_key' => [$unknown, '[redacted]'],
    'crlf' => [str_replace("\n", "\r\n", $unknown), '[redacted]'],
    'json_crlf' => [json_encode(str_replace("\n", "\r\n", $unknown)), json_encode('[redacted]')],
    // The known key hits exact-value matching; the unknown key exercises the complete-PEM regex.
    'complete_known' => [$pem, '[redacted]'],
    'complete_unknown' => [str_replace('RSA PRIVATE', 'EC PRIVATE', $pem), '[redacted]'],
    'complete_json' => [json_encode($pem), json_encode('[redacted]')],
    'short_body' => ["-----BEGIN PRIVATE KEY-----\nAb9/", "-----BEGIN PRIVATE KEY-----\nAb9/"],
    'public_key' => [str_replace('RSA PRIVATE', 'PUBLIC', $prefix), str_replace('RSA PRIVATE', 'PUBLIC', $prefix)],
    'already_redacted' => ['[redacted]', '[redacted]'],
];
foreach ($forms as $name => [$input, $expected]) {
    $actual = S\redact($input);
    $out['pem_forms'][$name] = [$actual, $expected, S\redact($actual)];
}
$row = (object) [
    'option_name' => 'novamira_oauth_private_key',
    'option_value' => $prefix,
    'nested' => ['row' => (object) ['secret' => str_repeat('e', 44), 'note' => 'ordinary text']],
];
$response = S\redact_ability_run_response(
    new WP_REST_Response(['return_value' => [$row]]),
    null,
    new WP_REST_Request('/wp-abilities/v1/abilities/novamira/execute-php/run'),
)->get_data();
$redacted_row = $response['return_value'][0];
$out['object_rows'] = $response;
$out['object_clones'] = [
    $redacted_row instanceof stdClass,
    $redacted_row !== $row,
    $redacted_row->nested['row'] !== $row->nested['row'],
    $row->option_value === $prefix,
    $row->nested['row']->secret === str_repeat('e', 44),
];
$custom = new ArrayObject(['secret' => $prefix]);
$subclass = new class extends stdClass {};
$subclass->secret = $prefix;
$out['other_objects_unchanged'] = [S\redact($custom) === $custom, S\redact($subclass) === $subclass];

$cyclic = (object) ['secret' => $prefix];
$cyclic->self = $cyclic;
$cyclic->nested = ['parent' => $cyclic];
$redacted_cycle = S\redact($cyclic);
$out['object_cycle'] = [
    $redacted_cycle !== $cyclic,
    $redacted_cycle->secret === '[redacted]',
    $redacted_cycle->self === $cyclic,
    $redacted_cycle->nested['parent'] === $cyclic,
    $cyclic->secret === $prefix,
    S\redact($cyclic)->secret === '[redacted]',
];
// A shared row is visited separately along each branch, rather than mistaken for a cycle.
$shared = S\redact([$row, $row]);
$out['shared_rows'] = [$shared[0]->option_value, $shared[1]->option_value];

echo json_encode($out);
