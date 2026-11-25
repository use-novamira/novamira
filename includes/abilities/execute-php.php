<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Ability: Execute PHP code.
 */

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/execute-php', [
    'label' => __('Execute PHP Code', domain: 'novamira'),
    'description' => __(
        'Executes PHP code on the WordPress server. The full WordPress environment is available including $wpdb, all WordPress functions, and loaded plugins. Returns the return value, any echoed output, and captured warnings/notices.',
        domain: 'novamira',
    ),
    'category' => 'code-execution',
    'input_schema' => [
        'type' => 'object',
        'properties' => [
            'code' => [
                'type' => 'string',
                'description' => 'PHP code to execute. Do NOT include <?php tags. Use "return $value;" to return data for inspection. Call functions by their written name (no $fn() or call_user_func); eval and create_function are refused while approvals are on. Check that plugin functions exist and results are not null or WP_Error, and declare no named functions or classes. Errors and exceptions already come back to you: no try/catch around the whole call.',
                'minLength' => 1,
            ],
        ],
        'required' => ['code'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'success' => ['type' => 'boolean', 'description' => 'Whether the code executed without throwing.'],
            'return_value' => [
                'type' => ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null'],
                'description' => 'The value returned by the evaluated code.',
            ],
            'output' => ['type' => 'string', 'description' => 'Any output captured via echo/print.'],
            'errors' => [
                'type' => 'array',
                'description' => 'PHP warnings, notices, and deprecations captured during execution.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'file' => ['type' => 'string'],
                        'line' => ['type' => 'integer'],
                        'count' => [
                            'type' => 'integer',
                            'description' => 'How many times this exact warning occurred.',
                        ],
                        'suppressed' => [
                            'type' => 'boolean',
                            'description' => 'Raised under the @ operator or excluded by error_reporting().',
                        ],
                    ],
                ],
            ],
            'errors_omitted' => [
                'type' => 'integer',
                'description' => 'Further distinct warnings not listed once the limit was reached.',
            ],
            'error_message' => ['type' => 'string', 'description' => 'Error message if execution failed.'],
            'error_class' => [
                'type' => 'string',
                'description' => 'Exception/Error class name if execution failed.',
            ],
            'execution_time_ms' => [
                'type' => 'number',
                'description' => 'Wall-clock execution time in milliseconds.',
            ],
        ],
    ],
    'execute_callback' => 'novamira_execute_php',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp' => ['public' => true],
        'annotations' => [
            'instructions' => implode("\n", [
                'Write plain code that can be checked before it runs and cannot end in a fatal error: this may be a live site.',
                '- Do not include <?php opening tags. Use "return $value;" to inspect values; echo and print output comes back in "output".',
                '- The full WordPress API and every loaded plugin API are available: $wpdb, get_option(), WP_Query and so on.',
                '- Never call exit() or die(): it ends the whole PHP process.',
                '- Never write an endless loop: execution has a ' . NOVAMIRA_MAX_EXECUTION_TIME . ' second time limit.',
                '- Never use eval or create_function: with approvals on, execute-php refuses them. Write that code directly in the call.',
                '- Call every function and method by its written name. Avoid $fn(), $obj->$method(), call_user_func,',
                '  include/require of a path built at runtime and shell commands: what such code changes can only be seen once',
                '  it runs, so it always waits for a person, who only has your description to go on.',
                '- Check before you use: function_exists / class_exists / method_exists before calling plugin code, and test',
                '  every result for null, false or is_wp_error() before reading from it.',
                '- No try/catch around the whole call: execute-php already returns any exception or error to you.',
                '- Do not declare named functions, classes or constants: a second run declares them again and ends in a fatal',
                '  error. Use closures. Code run here does not persist: code that must stay on the site goes in a file in the',
                '  sandbox (wp-content/novamira-sandbox/) through write-file.',
                '- Code that stays on the site, such as a fix for another plugin, is ordinary WordPress code: hook into that',
                '  plugin\'s own actions and filters, guard it with function_exists / class_exists so it does nothing when the',
                '  plugin is missing or changes, and let real errors show instead of catching them all.',
                '- Count or list before you change or delete many things, change only what the user asked for, and return',
                '  what you changed (IDs, counts) so it can be checked.',
                '- Never return a password or a password reset link. For a new user return the ID and login and send the',
                '  person WordPress\'s own email to set it: wp_new_user_notification( $user_id, null, \'user\' ). For an existing',
                '  user, retrieve_password( $login ). A password set during a call comes back to you as',
                '  "[redacted] (a password set in this call)".',
                '- Keep each call to one task and to PHP '
                    . PHP_MAJOR_VERSION
                    . '.'
                    . PHP_MINOR_VERSION
                    . '; no syntax newer than it.',
            ]),
            'readonly' => false,
            'destructive' => true,
            'idempotent' => false,
        ],
    ],
]);

/**
 * Execute PHP code.
 *
 * @param array $input Input with 'code' key.
 * @return array|WP_Error
 */
function novamira_execute_php($input)
{
    // An ability that runs PHP or WP-CLI commands also refuses on its own in read mode, even when it is called
    // without the registry or a permission check. Every other ability relies on the checks they all share.
    $read_mode_refusal = function_exists('novamira_read_mode_execution_refusal')
        ? novamira_read_mode_execution_refusal('novamira/execute-php')
        : null;
    if ($read_mode_refusal !== null) {
        return $read_mode_refusal;
    }

    $code = (string) $input['code'];

    // Save and set time limit.
    $original_time_limit = (int) ini_get('max_execution_time');
    set_time_limit(NOVAMIRA_MAX_EXECUTION_TIME);

    // Set up error handler to capture warnings/notices.
    /** @var array{errors: list<array<string, mixed>>, index: array<string, int>, omitted: int} $captured */
    $captured = ['errors' => [], 'index' => [], 'omitted' => 0];
    set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (
        &$captured,
    ): bool {
        novamira_execute_php_capture_error($captured, $errno, $errstr, $errfile, $errline);
        return true;
    });

    ob_start();
    $start = microtime(true);

    $return_value = null;
    $success = true;
    $error_message = null;
    $error_class = null;

    try {
        // @mago-expect lint:no-eval
        /** @var mixed $return_value */
        $return_value = eval($code);
    } catch (\Throwable $e) {
        $success = false;
        $error_message = $e->getMessage();
        $error_class = get_class($e);
    }

    $execution_time_ms = round(num: (microtime(true) - $start) * 1000, precision: 2);
    $output = ob_get_clean();

    restore_error_handler();
    set_time_limit($original_time_limit);

    // Ensure return value is JSON-serializable.
    if ($return_value !== null && json_encode($return_value) === false) {
        $return_value = print_r(value: $return_value, return: true);
    }

    $result = [
        'success' => $success,
        'return_value' => $return_value,
        'output' => $output,
        'errors' => $captured['errors'],
        'execution_time_ms' => $execution_time_ms,
    ];

    if ($captured['omitted'] > 0) {
        $result['errors_omitted'] = $captured['omitted'];
    }

    if ($error_message !== null) {
        $result['error_message'] = $error_message;
        $result['error_class'] = $error_class;
    }

    return $result;
}

/**
 * Record one warning, notice or deprecation raised by the executed code.
 *
 * Identical warnings are grouped so a callee that repeats one thousands of times (a recursive
 * delete under @, a notice in a loop) cannot flood the response; nothing is dropped silently.
 *
 * @param array{errors: list<array<string, mixed>>, index: array<string, int>, omitted: int} $captured
 */
function novamira_execute_php_capture_error(
    array &$captured,
    int $errno,
    string $errstr,
    string $errfile,
    int $errline,
): void {
    $error_types = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_DEPRECATED => 'Deprecated',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_USER_DEPRECATED => 'User Deprecated',
    ];

    $key = $errno . '|' . $errfile . '|' . $errline . '|' . $errstr;
    $position = $captured['index'][$key] ?? null;
    if ($position !== null) {
        $captured['errors'][$position]['count'] = (int) ($captured['errors'][$position]['count'] ?? 0) + 1;
        return;
    }
    if (count($captured['errors']) >= 100) {
        $captured['omitted']++;
        return;
    }
    $captured['index'][$key] = count($captured['errors']);
    $captured['errors'][] = [
        'type' => $error_types[$errno] ?? 'Unknown (' . $errno . ')',
        'message' => $errstr,
        'file' => $errfile,
        'line' => $errline,
        'count' => 1,
        // Under @ PHP 8 masks error_reporting() down to the fatal levels, so the bit is cleared.
        'suppressed' => (error_reporting() & $errno) === 0,
    ];
}
