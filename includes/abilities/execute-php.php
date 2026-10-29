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
                'description' => 'PHP code to execute. Do NOT include <?php tags. Use "return $value;" to return data for inspection.',
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
                'IMPORTANT SAFETY RULES:',
                '- Never call exit() or die() — this kills the entire PHP process.',
                '- Never create infinite loops — there is a 30-second time limit.',
                '- Do NOT include <?php opening tags.',
                '- Use "return $value;" to inspect values (the return value is captured).',
                '- Any echo/print output is captured separately in the "output" field.',
                '- The full WordPress API is available: $wpdb, get_option(), WP_Query, etc.',
                '- All loaded plugin APIs are available.',
                '- Execution has a ' . NOVAMIRA_MAX_EXECUTION_TIME . ' second time limit.',
                '',
                'SANDBOX CONTEXT:',
                '- To create persistent PHP functionality, write files to the sandbox',
                '  (wp-content/novamira-sandbox/) using the write-file ability.',
                '- Code executed here via eval() is temporary and does not persist across requests.',
                '- Do NOT use eval to require/include files that may have errors — use write-file',
                '  to persist them instead.',
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
