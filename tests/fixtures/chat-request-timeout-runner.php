<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for ChatRequestTimeoutTest: php chat-request-timeout-runner.php <plugin root> <scenario>.
 */

namespace WordPress\AiClient\Providers\Http\Exception {
    class NetworkException extends \RuntimeException {}
}

namespace {
    if ($argc !== 3) {
        exit(2);
    }

    [, $root, $scenario] = $argv;

    const ABSPATH = '/';

    $GLOBALS['filters'] = [];

    class WP_Error
    {
        /** @param array<string, mixed> $data */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private array $data = [],
        ) {}

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }

    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }

    function add_action(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }

    function add_filter(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['filters'][$hook][$priority][] = $callback;
        return true;
    }

    function has_filter(string $hook, callable|string|false $callback = false): bool|int
    {
        foreach ($GLOBALS['filters'][$hook] ?? [] as $priority => $callbacks) {
            if ($callback === false && $callbacks !== []) {
                return true;
            }
            if ($callback !== false && in_array($callback, $callbacks, strict: true)) {
                return $priority;
            }
        }
        return false;
    }

    function remove_filter(string $hook, callable|string $callback, int $priority = 10): bool
    {
        $callbacks = $GLOBALS['filters'][$hook][$priority] ?? [];
        $index = array_search($callback, $callbacks, strict: true);
        if ($index === false) {
            return false;
        }
        unset($GLOBALS['filters'][$hook][$priority][$index]);
        return true;
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $by_priority = $GLOBALS['filters'][$hook] ?? [];
        ksort($by_priority);
        foreach ($by_priority as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = $callback($value, ...$args);
            }
        }
        return $value;
    }

    /**
     * Stand-in for the WordPress 7.0 AI Client: like WP_AI_Client_Prompt_Builder, it reads the default
     * request timeout from the core filter when the builder is constructed.
     */
    function wp_ai_client_prompt(mixed $prompt = null): object
    {
        if ($GLOBALS['throw_on_prompt'] ?? false) {
            throw new RuntimeException('Builder construction failed.');
        }
        return (object) ['timeout' => apply_filters('wp_ai_client_default_request_timeout', 30.0)];
    }

    require $root . '/includes/chat.php';

    $log = tempnam(sys_get_temp_dir(), 'novamira-chat-timeout');
    ini_set('error_log', $log);

    $timeout_message =
        'Network error occurred while sending POST request to https://api.anthropic.com/v1/messages: '
        . 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';

    $describe = static function (WP_Error|Throwable $error): array {
        return [
            'timeout' => novamira_chat_is_request_timeout($error),
            'message' => novamira_chat_model_error_message($error),
        ];
    };

    // A site filter on the hook, added before Chat builds its prompt; the result also reports whether
    // Chat's callback was left attached.
    $with_site_filter = static function (int $priority, float $value): array {
        add_filter('wp_ai_client_default_request_timeout', static fn(): float => $value, $priority);
        return [
            'timeout' => novamira_chat_prompt_builder([])->timeout,
            'chat_attached' => has_filter('wp_ai_client_default_request_timeout', 'novamira_chat_request_timeout') !== false,
        ];
    };

    $result = match ($scenario) {
        'default' => [
            'timeout' => novamira_chat_prompt_builder([])->timeout,
            'after' => apply_filters('wp_ai_client_default_request_timeout', 30.0),
        ],
        'site-earliest-30' => $with_site_filter(PHP_INT_MIN, 30.0),
        'site-earliest-45' => $with_site_filter(PHP_INT_MIN, 45.0),
        'site-earliest-0' => $with_site_filter(PHP_INT_MIN, 0.0),
        'site-earliest-300' => $with_site_filter(PHP_INT_MIN, 300.0),
        'site-default-45' => $with_site_filter(10, 45.0),
        'site-latest-300' => $with_site_filter(PHP_INT_MAX, 300.0),
        'throws' => (static function (): array {
            $GLOBALS['throw_on_prompt'] = true;
            try {
                novamira_chat_prompt_builder([]);
            } catch (RuntimeException) {
            }
            return ['after' => apply_filters('wp_ai_client_default_request_timeout', 30.0)];
        })(),
        'errors' => [
            'wp_error_timeout' => $describe(new WP_Error('prompt_network_error', $timeout_message, ['status' => 503])),
            'wp_error_timed_out' => $describe(new WP_Error(
                'prompt_network_error',
                'Network error occurred while sending POST request to https://example.test: fsocket timed out',
            )),
            'wp_error_network_other' => $describe(new WP_Error(
                'prompt_network_error',
                'Network error occurred while sending POST request to https://example.test: cURL error 6: Could not resolve host',
            )),
            'wp_error_other_code' => $describe(new WP_Error('prompt_client_error', 'Request timed out on the client side.')),
            'exception_timeout' => $describe(
                new \WordPress\AiClient\Providers\Http\Exception\NetworkException($timeout_message),
            ),
            'exception_other' => $describe(new RuntimeException('cURL error 28: Operation timed out')),
            'log' => (string) file_get_contents($log),
        ],
        default => ['unknown_scenario' => $scenario],
    };

    unlink($log);
    echo json_encode($result);
}
