<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for ChatCallsTest: the real Chat functions around a stand-in for the fixed checks and the lock.
 */

namespace Novamira\Confirmations {
    /** @return array{refused: string, must_ask: bool, fingerprint: string, why: string} */
    function chat_call_check(string $ability_name, array $arguments, ?int $attention = null): array
    {
        return $GLOBALS['check'] + ['refused' => '', 'must_ask' => false, 'fingerprint' => '', 'why' => ''];
    }

    function chat_approval_mode(): string
    {
        return $GLOBALS['approval_mode'] ?? 'chat';
    }
}

namespace {
    const ABSPATH = '/tmp/';

    function add_action(string $hook, mixed $callback, int $priority = 10): void {}

    function __(string $text, string $domain = ''): string
    {
        return $text;
    }

    function sanitize_text_field(string $text): string
    {
        return trim($text);
    }

    function get_current_user_id(): int
    {
        return 1;
    }

    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }

    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public array $data = [],
        ) {}
    }

    class WP_REST_Request implements ArrayAccess
    {
        public function __construct(
            private array $params = [],
        ) {}

        public function get_json_params(): array
        {
            return $this->params;
        }

        public function offsetExists(mixed $offset): bool
        {
            return false;
        }

        public function offsetGet(mixed $offset): mixed
        {
            return null;
        }

        public function offsetSet(mixed $offset, mixed $value): void {}

        public function offsetUnset(mixed $offset): void {}
    }

    class wpdb
    {
        public string $prefix = 'wp_';

        /** @var list<string> */
        public array $queries = [];

        public function __construct(
            public ?string $lock_answer,
        ) {}

        public function prepare(string $query, mixed ...$args): string
        {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function get_var(string $query): ?string
        {
            $this->queries[] = $query;
            return str_contains($query, 'GET_LOCK') ? $this->lock_answer : '1';
        }
    }

    require $argv[1] . '/includes/chat-schema.php';
    require $argv[1] . '/includes/chat.php';

    $proposed = static function (string $reason, array $tool_risk, array $check): array {
        $GLOBALS['check'] = $check;
        return novamira_chat_ability_call_fields(
            ['id' => 's'],
            'novamira/execute-php',
            ['code' => 'x'],
            $reason,
            [['name' => 'novamira/execute-php', 'risk' => $tool_risk]],
        );
    };
    $at_run_time = static function (array $call, array $check): array {
        $GLOBALS['check'] = $check;
        $session = novamira_chat_recheck_call(['id' => 's', 'tool_calls' => [$call]], 0);
        $after = $session['tool_calls'][0];
        $ran = false;
        $outcome = novamira_chat_run_ability(
            $session,
            [
                'ability' => new class($ran) {
                    public function __construct(
                        private bool &$ran,
                    ) {}

                    public function execute(array $arguments): string
                    {
                        $this->ran = true;
                        return 'ran';
                    }
                },
                'ability_name' => 'novamira/execute-php',
                'tool_call' => $after,
            ],
            [],
        );
        return ['status' => $after['status'], 'ran' => $ran, 'refused' => is_wp_error($outcome)];
    };

    $out = [];

    // The model leaves out its words on a call that needs a person: refused, and still refused when it comes to run.
    $no_words = $proposed('', ['requires_approval' => true], []);
    $out['no_words'] = [
        'status' => $no_words['status'],
        'refused' => $no_words['risk']['refused'] !== '',
        'at_run_time' => $at_run_time($no_words, []),
    ];

    // The same with a check that wants a person: no card that approving could never satisfy.
    $no_words_check = $proposed('', ['requires_approval' => false], ['must_ask' => true, 'fingerprint' => 'f']);
    $out['no_words_check'] = [
        'status' => $no_words_check['status'],
        'must_ask' => $no_words_check['risk']['must_ask'],
        'at_run_time' => $at_run_time($no_words_check, ['must_ask' => true, 'fingerprint' => 'f']),
    ];

    // A call the checks refused does not come back when they no longer refuse it.
    $check_refused = $proposed('Does a thing.', ['requires_approval' => true], ['refused' => 'No.']);
    $out['check_refused'] = $at_run_time($check_refused, []);

    // With its words, a call needing a person waits for one, and still runs once approved.
    $with_words = $proposed('Does a thing.', ['requires_approval' => true], []);
    $out['with_words_status'] = $with_words['status'];
    $with_words['status'] = 'approved';
    $out['with_words_at_run_time'] = $at_run_time($with_words, []);

    // A read asks for nothing, and needs no words.
    $read = $proposed('', ['requires_approval' => false], []);
    $out['read'] = ['status' => $read['status'], 'at_run_time' => $at_run_time($read, [])];

    // With approvals on, Chat asks as MCP does: a write the checks let through runs without a card.
    $GLOBALS['approval_mode'] = 'novamira';
    $out['aligned'] = [
        'small_write' => $proposed('Change a title?', ['requires_approval' => true], [])['status'],
        'over_cutoff' => $proposed('Turn off every plugin?', ['requires_approval' => true], ['must_ask' => true, 'fingerprint' => 'f'])['status'],
    ];
    // Approvals skipped: nothing waits.
    $GLOBALS['approval_mode'] = 'nobody';
    $out['skipped'] = $proposed('Change a title?', ['requires_approval' => true], [])['status'];
    $GLOBALS['approval_mode'] = 'chat';

    // The lock: held, busy, and a database that gives no answer.
    foreach (['held' => '1', 'busy' => '0', 'no_answer' => null] as $name => $answer) {
        $GLOBALS['wpdb'] = new wpdb($answer);
        $reached = false;
        $route = novamira_chat_one_at_a_time(
            static function (WP_REST_Request $request) use (&$reached): array {
                $reached = true;
                return ['session' => []];
            },
            wait_seconds: 5,
        );
        $result = $route(new WP_REST_Request(['session_id' => 'abc']));
        $out['lock'][$name] = [
            'reached' => $reached,
            'status' => is_wp_error($result) ? $result->data['status'] : 200,
            'waits' => str_contains($GLOBALS['wpdb']->queries[0], ', 5)'),
            'released' => count(array_filter(
                $GLOBALS['wpdb']->queries,
                static fn(string $query): bool => str_contains($query, 'RELEASE_LOCK'),
            )),
        ];
    }

    // A route that throws still gives the lock back.
    $GLOBALS['wpdb'] = new wpdb('1');
    try {
        novamira_chat_one_at_a_time(static function (WP_REST_Request $request): array {
            throw new RuntimeException('boom');
        }, wait_seconds: 0)(new WP_REST_Request(['session_id' => 'abc']));
    } catch (RuntimeException) {
        $out['lock']['released_after_throw'] = str_contains(implode(' ', $GLOBALS['wpdb']->queries), 'RELEASE_LOCK');
    }

    echo json_encode($out);
}
