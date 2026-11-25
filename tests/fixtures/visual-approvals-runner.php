<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for VisualApprovalsTest: the real Visual backend tool calls around a stand-in for the
 * approvals' checks, the AI change history and WordPress.
 */

namespace Novamira\Confirmations {
    final class RunningApproval
    {
        /** @var array{by: int, says: string}|null */
        public static ?array $current = null;
    }

    /** @return array{refused: string, must_ask: bool, fingerprint: string, why: string} */
    function chat_call_check(string $ability, array $arguments, ?int $attention = null): array
    {
        $GLOBALS['seen_attention'][] = $attention;
        return $GLOBALS['check'] + ['refused' => '', 'must_ask' => false, 'fingerprint' => '', 'why' => ''];
    }

    function chat_approval_mode(): string
    {
        return $GLOBALS['mode'];
    }

    function settings(): array
    {
        return ['cutoff' => 5];
    }

    function site_approval_instructions(): string
    {
        return 'Rate cancelling an event 10.';
    }

    function session_id(?string $session = null): string
    {
        $GLOBALS['session'] = $session;
        return (string) $session;
    }
}

namespace Novamira\Journal {
    function run_direct_call(string $ability, array $arguments, string $message_id, callable $run, string $says = ''): mixed
    {
        $GLOBALS['recorded'][] = $ability;
        $approval = \Novamira\Confirmations\RunningApproval::$current;
        $GLOBALS['said'][] = [$says, $approval['by'] ?? 0];
        return $run();
    }
}

namespace Novamira\Secrets {
    function redact_tool_result(mixed $result): mixed
    {
        return is_array($result) ? ['redacted' => true] + $result : $result;
    }
}

namespace {
    const ABSPATH = '/tmp/';

    function add_action(string $hook, mixed $callback, int $priority = 10): void {}

    function __(string $text, string $domain = ''): string
    {
        return $text;
    }

    function get_current_user_id(): int
    {
        return $GLOBALS['user'];
    }

    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }

    function apply_filters(string $hook, mixed $value): mixed
    {
        return $value;
    }

    function set_transient(string $key, mixed $value, int $ttl): bool
    {
        $GLOBALS['transients'][$key] = $value;
        return true;
    }

    function get_transient(string $key): mixed
    {
        return $GLOBALS['transients'][$key] ?? false;
    }

    function delete_transient(string $key): bool
    {
        if (!array_key_exists($key, $GLOBALS['transients'])) {
            return false;
        }
        unset($GLOBALS['transients'][$key]);
        return true;
    }

    function wp_get_ability(string $name): ?WP_Ability
    {
        return new WP_Ability($name);
    }

    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public array $data = [],
        ) {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    }

    class WP_Ability
    {
        public function __construct(
            private string $name,
        ) {}

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_meta(): array
        {
            return ['mcp' => ['public' => true, 'type' => 'tool']];
        }

        public function execute(mixed $input): mixed
        {
            $GLOBALS['ran'][] = [$this->name, $input];
            return ['done' => true];
        }
    }

    require $argv[1] . '/novamira-visual/includes/BackendTools.php';
    require $argv[1] . '/novamira-visual/includes/BackendApprovals.php';

    use Novamira\Visual\BackendTools as V;

    function reset_state(string $mode, array $check = []): void
    {
        $GLOBALS['mode'] = $mode;
        $GLOBALS['check'] = $check;
        $GLOBALS['user'] = 1;
        $GLOBALS['ran'] = [];
        $GLOBALS['recorded'] = [];
        $GLOBALS['said'] = [];
        $GLOBALS['transients'] = [];
        $GLOBALS['seen_attention'] = [];
        $GLOBALS['session'] = null;
    }

    function outcome(mixed $result): array
    {
        return [
            'result' => $result instanceof WP_Error
                ? ['error' => $result->message, 'status' => $result->data['status'] ?? null]
                : $result,
            'ran' => count($GLOBALS['ran']),
            'recorded' => $GLOBALS['recorded'],
            'said' => $GLOBALS['said'],
        ];
    }

    $call = ['name' => 'novamira/execute-php', 'args' => ['code' => 'return 1;'], 'attention' => 9, 'reason' => 'Returns one.'];
    $out = [];

    reset_state('nobody');
    $out['skipped'] = outcome(V\call_data($call)) + ['session' => $GLOBALS['session']];

    reset_state('novamira');
    $out['clear'] = outcome(V\call_data($call));

    reset_state('novamira', ['refused' => 'Refused for a reason.']);
    $out['refused'] = outcome(V\call_data($call));

    reset_state('novamira', ['must_ask' => true, 'fingerprint' => 'a', 'why' => 'Changes users.']);
    $pending = V\call_data($call);
    $out['pending'] = outcome($pending);
    $id = is_array($pending) ? (string) ($pending['approval_id'] ?? '') : '';
    $GLOBALS['user'] = 2;
    $out['other_user'] = outcome(V\decide_data(['approval_id' => $id, 'decision' => 'approve']));
    $GLOBALS['user'] = 1;
    $out['approved'] = outcome(V\decide_data(['approval_id' => $id, 'decision' => 'approve']));
    $out['approved_again'] = outcome(V\decide_data(['approval_id' => $id, 'decision' => 'approve']));

    reset_state('novamira', ['must_ask' => true, 'fingerprint' => 'a']);
    $pending = V\call_data($call);
    $id = is_array($pending) ? (string) ($pending['approval_id'] ?? '') : '';
    $out['denied'] = outcome(V\decide_data(['approval_id' => $id, 'decision' => 'deny']));
    $out['denied_left'] = count($GLOBALS['transients']);

    reset_state('novamira', ['must_ask' => true, 'fingerprint' => 'a']);
    $pending = V\call_data($call);
    $id = is_array($pending) ? (string) ($pending['approval_id'] ?? '') : '';
    $GLOBALS['check']['fingerprint'] = 'b';
    $out['changed'] = outcome(V\decide_data(['approval_id' => $id, 'decision' => 'approve']));

    reset_state('novamira', ['must_ask' => true, 'fingerprint' => 'a']);
    $pending = V\call_data($call);
    $id = is_array($pending) ? (string) ($pending['approval_id'] ?? '') : '';
    $GLOBALS['mode'] = 'nobody';
    $out['skipped_after_card'] = outcome(V\decide_data(['approval_id' => $id, 'decision' => 'approve']));

    reset_state('novamira');
    $out['unknown_id'] = outcome(V\decide_data(['approval_id' => '../x', 'decision' => 'approve']));

    reset_state('novamira');
    V\call_data(['attention' => '7'] + $call);
    V\call_data(['attention' => 15] + $call);
    V\call_data(['attention' => null] + $call);
    $out['attention'] = $GLOBALS['seen_attention'];

    $out['workspace_approvals'] = (static function (): array {
        reset_state('novamira');
        return V\workspace_approvals();
    })();
    // With approvals skipped the AI is told nothing about what to approve.
    $out['workspace_approvals_skipped'] = (static function (): array {
        reset_state('nobody');
        return V\workspace_approvals();
    })();

    echo json_encode($out, JSON_THROW_ON_ERROR);
}
