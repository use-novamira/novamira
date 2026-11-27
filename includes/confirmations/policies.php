<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Whether an ability belongs to Novamira or Novamira Pro. Confirmations and held emails cover only these: another plugin's
 * ability is that plugin's to answer for, unless an administrator chose to confirm it in the Abilities Hub.
 */
function ability_is_ours(string $ability): bool
{
    foreach (['novamira/', 'novamira-pro/', 'novamira-mcp-adapter/'] as $prefix) {
        if (str_starts_with($ability, $prefix)) {
            return true;
        }
    }
    if (!function_exists('Novamira\\Features\\features')) {
        return false;
    }
    $registered = function_exists('wp_get_ability') ? wp_get_ability($ability) : null;
    $category = $registered instanceof \WP_Ability ? $registered->get_category() : '';
    return \Novamira\Features\features()->feature_for_ability($ability, $category)?->provider === 'Novamira Pro';
}

/** What a person reads when an ability asks only because an administrator chose so. */
const FORCED_REASON = 'An administrator chose to confirm this ability every time it runs.';

/** What an administrator chose for each ability in the Abilities Hub: "yes" always asks, "no" never asks. */
const CHOICES_OPTION = 'novamira_confirmation_ability_choices';

/** Abilities that run code or touch files and access: they can be made to always ask, never to stop asking. */
const NEVER_NO = [
    'novamira/execute-php',
    'novamira/run-wp-cli',
    'novamira/write-file',
    'novamira/edit-file',
    'novamira/delete-file',
    'novamira/create-upload-link',
    'novamira/enable-file',
];

/** Abilities that always ask, whatever is chosen. */
const ALWAYS_YES = ['novamira/create-admin-access-link'];

/** @return array<string, 'yes'|'no'> */
function ability_choices(): array
{
    /** @var mixed $stored */
    $stored = get_option(CHOICES_OPTION, []);
    $choices = [];
    // @mago-expect analysis:mixed-assignment -- Stored choices are checked one by one.
    foreach (is_array($stored) ? $stored : [] as $ability => $choice) {
        if (!is_string($ability) || $choice !== 'yes' && $choice !== 'no') {
            continue;
        }
        $choices[$ability] = $choice;
    }
    return $choices;
}

/**
 * How an ability asks: "level" follows the chosen approval level, "yes" always asks, "no" never asks. A choice an
 * ability does not allow reads as the level.
 *
 * @return 'level'|'yes'|'no'
 */
function ability_choice(string $ability): string
{
    if (in_array($ability, ALWAYS_YES, strict: true)) {
        return 'yes';
    }
    $choice = ability_choices()[$ability] ?? 'level';
    return $choice === 'no' && in_array($ability, NEVER_NO, strict: true) ? 'level' : $choice;
}

/** @return list<'level'|'yes'|'no'> The choices the Abilities Hub offers for an ability. */
function allowed_choices(string $ability): array
{
    if (in_array($ability, ALWAYS_YES, strict: true)) {
        return [];
    }
    return in_array($ability, NEVER_NO, strict: true) ? ['level', 'yes'] : ['level', 'yes', 'no'];
}

/** Save a choice from the Abilities Hub. False when the ability does not allow it. */
function set_ability_choice(string $ability, string $choice): bool
{
    if (!in_array($choice, allowed_choices($ability), strict: true)) {
        return false;
    }
    $choices = ability_choices();
    unset($choices[$ability]);
    if ($choice !== 'level') {
        $choices[$ability] = $choice;
    }
    update_option(CHOICES_OPTION, $choices, autoload: false);
    return true;
}

/**
 * The treatment of an ability, with what an administrator chose for it in the Abilities Hub.
 *
 * @return array{score: bool, confirm: 'never'|'unchecked'|'above'|'always', check: string, floor: int}
 */
function ability_policy(string $ability): array
{
    $policy = listed_ability_policy($ability);
    return match (ability_choice($ability)) {
        'yes' => array_merge($policy, ['confirm' => 'always']),
        'no' => array_merge($policy, ['confirm' => 'never', 'score' => false]),
        default => $policy,
    };
}

/**
 * The treatment one of Novamira's abilities declares where it is registered, in its meta as novamira_approval:
 * "never" asks, "level" takes the AI's score and asks from the chosen level, "always" asks. Null when it declares none.
 *
 * @return array{score: bool, confirm: 'never'|'unchecked'|'above'|'always', check: string, floor: int}|null
 */
function declared_ability_policy(string $ability): ?array
{
    if (!ability_is_ours($ability) || !function_exists('wp_get_ability')) {
        return null;
    }
    $registered = wp_get_ability($ability);
    if (!$registered instanceof \WP_Ability) {
        return null;
    }
    /** @var mixed $approval */
    $approval = $registered->get_meta()['novamira_approval'] ?? null;
    return match ($approval) {
        'never' => ['score' => false, 'confirm' => 'never', 'check' => '', 'floor' => 0],
        'level' => ['score' => true, 'confirm' => 'above', 'check' => '', 'floor' => 0],
        'always' => ['score' => false, 'confirm' => 'always', 'check' => '', 'floor' => 0],
        default => null,
    };
}

/**
 * How confirmations treat each ability.
 *
 * - score: whether the AI must send review.attention. Where what matters depends on a path or a command the server
 *   already knows, a fixed check decides instead and the AI's score is not asked for.
 * - confirm: "never" runs at once and stays out of the history, "unchecked" runs at once and is recorded, "above" asks
 *   when the score used reaches the cutoff, "always" asks every time.
 * - check: the fixed check that sets a minimum score (see ability_check()).
 * - floor: a minimum score of its own.
 *
 * Abilities not listed follow the chosen level with the AI's rating, unless they declare novamira_approval (Novamira's
 * own) or are registered as read-only.
 *
 * @return array{score: bool, confirm: 'never'|'unchecked'|'above'|'always', check: string, floor: int}
 */
function listed_ability_policy(string $ability): array
{
    $never = ['score' => false, 'confirm' => 'never', 'check' => '', 'floor' => 0];
    $known = ['score' => true, 'confirm' => 'above', 'check' => '', 'floor' => 0];
    $policies = [
        // Reads, discovery, and Gutenberg changes that stay pending until a person commits them in the browser.
        'novamira/agent-context' => $never,
        'novamira-mcp-adapter/discover-abilities' => $never,
        'novamira/list-directory' => $never,
        'novamira/read-file' => $never,
        'novamira/get-wp-cli-job' => $never,
        'novamira/disable-file' => $never,
        'novamira/get-design' => $never,
        'novamira/get-active-design' => $never,
        'novamira/list-design-library' => $never,
        'novamira/check-design' => $never,
        'novamira/skill-get' => $never,
        'novamira/gutenberg-create-pending-batch' => $never,
        'novamira/gutenberg-add-pending-change' => $never,
        'novamira/gutenberg-delete-pending-change' => $never,
        'novamira/gutenberg-delete-pending-batch' => $never,
        'novamira/gutenberg-get-finalization-url' => $never,
        'novamira/gutenberg-enable-batch-finalization' => $never,
        'novamira/gutenberg-get-block-type' => $never,
        'novamira/gutenberg-get-content' => $never,
        'novamira/gutenberg-get-finalizer-runtime' => $never,
        'novamira/gutenberg-get-pending-batch' => $never,
        'novamira/gutenberg-list-block-types' => $never,
        'novamira/gutenberg-list-pending-batches' => $never,
        // The AI change history: words about what happened.
        'novamira/list-changes' => $never,
        'novamira/describe-changes' => $never,
        'novamira/get-saved-row' => $never,
        // Polls a request a person decides on: asking about it would never end.
        'novamira/confirmation-status' => $never,
        // Code that runs: the AI scores it and the code check sets the minimum.
        'novamira/execute-php' => array_merge($known, ['check' => 'php_code']),
        'novamira/write-file' => array_merge($known, ['check' => 'file_write']),
        'novamira/edit-file' => array_merge($known, ['check' => 'file_write']),
        'novamira/run-wp-cli' => array_merge($known, ['check' => 'wp_cli']),
        // What matters is in the path: a fixed check decides.
        'novamira/delete-file' => ['score' => false, 'confirm' => 'above', 'check' => 'file_delete', 'floor' => 0],
        // PHP goes only to the sandbox, saved disabled, and runs only through enable-file: what matters is replacing
        // a file outside it.
        'novamira/create-upload-link' => [
            'score' => false,
            'confirm' => 'above',
            'check' => 'upload_link',
            'floor' => 0,
        ],
        // Rare and powerful: always a person's decision.
        // The check reads the file that is about to run on every request; turning it on counts as a big change.
        'novamira/enable-file' => ['score' => true, 'confirm' => 'above', 'check' => 'file_enable', 'floor' => 0],
        'novamira/create-admin-access-link' => ['score' => false, 'confirm' => 'always', 'check' => '', 'floor' => 0],
        // Novamira's other abilities change known things: the AI's score alone.
        'novamira/gutenberg-write-content' => $known,
        'novamira/save-design' => $known,
        'novamira/activate-design' => $known,
        'novamira/delete-design' => $known,
        'novamira/skill-write' => $known,
        'novamira/skill-edit' => $known,
        'novamira/skill-delete' => $known,
    ];
    // An ability registered as read-only runs at once, another plugin's included; Always ask in the Abilities Hub still
    // makes it ask. Any other ability follows the chosen level with the AI's rating: Novamira does not check what it
    // does, so the AI judges it from its description.
    return (
        $policies[$ability] ?? declared_ability_policy($ability) ?? (ability_is_readonly($ability) ? $never : $known)
    );
}

/**
 * Whether an ability is registered as read-only: the Abilities API's readonly annotation, or MCP's readOnlyHint, which
 * some plugins use instead and which wins when both are there, as in the MCP Adapter.
 */
function ability_is_readonly(string $ability): bool
{
    if (!function_exists('wp_get_ability')) {
        return false;
    }
    $registered = wp_get_ability($ability);
    if (!$registered instanceof \WP_Ability) {
        return false;
    }
    /** @var mixed $annotations */
    $annotations = $registered->get_meta()['annotations'] ?? null;
    if (!is_array($annotations)) {
        return false;
    }
    return ($annotations['readOnlyHint'] ?? $annotations['readonly'] ?? null) === true;
}

/** @return list<string> Abilities that need an attention level: listed for the AI in the tool description. */
function abilities_with_score(): array
{
    return [...listed_abilities_with_score(), ...declared_abilities_with_score()];
}

/** @return list<string> Registered abilities of Novamira that declare they take the AI's score. */
function declared_abilities_with_score(): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }
    $names = [];
    foreach (wp_get_abilities() as $ability) {
        if (($ability->get_meta()['novamira_approval'] ?? null) !== 'level' || !ability_is_ours($ability->get_name())) {
            continue;
        }
        $names[] = $ability->get_name();
    }
    return $names;
}

/** @return list<string> Novamira's own abilities that take the AI's score. */
function listed_abilities_with_score(): array
{
    return [
        'novamira/execute-php',
        'novamira/write-file',
        'novamira/edit-file',
        'novamira/run-wp-cli',
        'novamira/gutenberg-write-content',
        'novamira/save-design',
        'novamira/activate-design',
        'novamira/delete-design',
        'novamira/skill-write',
        'novamira/skill-edit',
        'novamira/skill-delete',
    ];
}

/**
 * Run an ability's fixed check. Null when the ability has none.
 *
 * @param array{score: bool, confirm: string, check: string, floor: int} $policy
 * @param array<array-key, mixed> $args mcp-adapter-execute-ability arguments.
 * @return array{score: int, rules: list<string>, reject: bool}|null
 */
function ability_check(array $policy, array $args): ?array
{
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    try {
        $result = match ($policy['check']) {
            'php_code' => analyze_call($args),
            'file_write' => file_write_check($parameters),
            'file_enable' => file_enable_check($parameters),
            'file_delete' => file_delete_check($parameters),
            'upload_link' => upload_link_check($parameters),
            'wp_cli' => wp_cli_check($parameters),
            default => null,
        };
    } catch (\Throwable) {
        // A check that fails sends the call to a person: it never lets the call through on a guess.
        $result = analysis_result([analysis_available() ? 'refuse:check_failed' : 'refuse:check_unavailable']);
    }
    if ($policy['floor'] <= 0) {
        return $result;
    }
    $rules = $result['rules'] ?? [];
    $floor = $policy['floor'] >= ANALYSIS_HIGH ? 'high:ability_minimum' : 'medium:ability_minimum';
    return analysis_result([...$rules, $floor]);
}

/** Resolve an ability's path argument the way the file abilities do, or null when it cannot be resolved. */
function target_path(mixed $path): ?string
{
    if (!is_string($path) || $path === '' || !function_exists('novamira_resolve_path')) {
        return null;
    }
    /** @var mixed $resolved */
    $resolved = novamira_resolve_path($path, must_exist: false);
    return is_string($resolved) ? $resolved : null;
}

function in_sandbox(string $path): bool
{
    if (!function_exists('novamira_get_sandbox_dir') || !function_exists('novamira_path_is_within_directory')) {
        return false;
    }
    /** @var mixed $sandbox */
    $sandbox = novamira_get_sandbox_dir();
    return is_string($sandbox) && novamira_path_is_within_directory($path, $sandbox);
}

/**
 * write-file and edit-file: a PHP file is read by the code check as it will be after the change. Other files
 * are a medium change outside the sandbox.
 *
 * @param array<array-key, mixed> $parameters
 * @return array{score: int, rules: list<string>, reject: bool}
 */
function file_write_check(array $parameters): array
{
    $path = target_path($parameters['path'] ?? null);
    if ($path === null) {
        return analysis_result(['unverified:path']);
    }
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        return analysis_result(in_sandbox($path) ? [] : ['medium:file_outside_sandbox']);
    }
    $source = final_file_content($path, $parameters);
    return $source === null ? analysis_result(['unverified:file_content']) : analyze_source($source);
}

/**
 * The content a file will have after write-file (overwrite or append) or edit-file (old_string → new_string).
 *
 * @param array<array-key, mixed> $parameters
 */
function final_file_content(string $path, array $parameters): ?string
{
    $existing = is_file($path) ? file_get_contents($path) : '';
    if (!is_string($existing)) {
        return null;
    }
    if (is_string($parameters['content'] ?? null)) {
        return ($parameters['mode'] ?? 'overwrite') === 'append'
            ? $existing . $parameters['content']
            : $parameters['content'];
    }
    return edited_content($existing, $parameters);
}

/**
 * The content after edit-file replaces old_string with new_string (once, or everywhere with replace_all).
 *
 * @param array<array-key, mixed> $parameters
 */
function edited_content(string $existing, array $parameters): ?string
{
    $old = is_string($parameters['old_string'] ?? null) ? $parameters['old_string'] : '';
    $new = is_string($parameters['new_string'] ?? null) ? $parameters['new_string'] : null;
    $position = $old === '' ? false : strpos($existing, $old);
    if ($position === false || $new === null) {
        return null;
    }
    if (($parameters['replace_all'] ?? false) === true) {
        return str_replace($old, $new, $existing);
    }
    return substr_replace($existing, $new, $position, strlen($old));
}

/**
 * create-upload-link: a new file, or one in the sandbox, is ordinary work. Replacing a file outside the sandbox is a
 * medium change: what it held is not in the history.
 *
 * @param array<array-key, mixed> $parameters
 * @return array{score: int, rules: list<string>, reject: bool}
 */
function upload_link_check(array $parameters): array
{
    $path = target_path($parameters['path'] ?? null);
    $replaces =
        $path !== null && ($parameters['overwrite'] ?? false) === true && file_exists($path) && !in_sandbox($path);
    return analysis_result($replaces ? ['medium:replaces_file'] : []);
}

/**
 * enable-file: the code check reads the file that is about to start running on every request.
 *
 * @param array<array-key, mixed> $parameters
 * @return array{score: int, rules: list<string>, reject: bool}
 */
function file_enable_check(array $parameters): array
{
    $path = target_path($parameters['path'] ?? null);
    $source = $path !== null && is_file($path) ? file_get_contents($path) : false;
    $read = is_string($source) ? analyze_source($source) : analysis_result(['unverified:file_content']);
    return analysis_result([...$read['rules'], 'big:runs_on_every_request']);
}

/**
 * delete-file: deleting code (plugins, themes, must-use plugins) or a whole directory is high, anything else
 * medium.
 *
 * @param array<array-key, mixed> $parameters
 * @return array{score: int, rules: list<string>, reject: bool}
 */
function file_delete_check(array $parameters): array
{
    $path = target_path($parameters['path'] ?? null);
    if ($path === null) {
        return analysis_result(['unverified:path']);
    }
    $code_directories = array_filter([
        defined('WP_PLUGIN_DIR') ? (string) constant('WP_PLUGIN_DIR') : '',
        defined('WPMU_PLUGIN_DIR') ? (string) constant('WPMU_PLUGIN_DIR') : '',
        function_exists('get_theme_root') ? get_theme_root() : '',
    ]);
    foreach ($code_directories as $directory) {
        if (
            function_exists('novamira_path_is_within_directory') && novamira_path_is_within_directory($path, $directory)
        ) {
            return analysis_result(['high:deletes_code']);
        }
    }
    if (($parameters['recursive'] ?? false) === true || is_dir($path)) {
        return analysis_result(['high:deletes_directory']);
    }
    // The sandbox's .htaccess and web.config stop its PHP files from opening on the web: losing them shows nowhere.
    if (in_sandbox($path) && in_array(basename($path), ['.htaccess', 'web.config'], strict: true)) {
        return analysis_result(['high:deletes_sandbox_protection']);
    }
    return analysis_result(['medium:deletes_file']);
}

/**
 * The treatment a call gets, from its ability and its arguments: enabling a sandbox file that already runs changes
 * nothing, so it does not ask (unless an administrator forced the ability in the Abilities Hub).
 *
 * @return array{score: bool, confirm: 'never'|'unchecked'|'above'|'always', check: string, floor: int}
 */
function call_policy(string $ability, mixed $parameters): array
{
    $policy = ability_policy($ability);
    $parameters = is_array($parameters) ? $parameters : [];
    if (
        $ability === 'novamira/enable-file'
        && ability_choice($ability) !== 'yes'
        && sandbox_file_already_runs($parameters)
    ) {
        $policy['confirm'] = 'never';
    }
    return $policy;
}

/** @param array<array-key, mixed> $parameters */
function sandbox_file_already_runs(array $parameters): bool
{
    $path = target_path($parameters['path'] ?? null);
    if (
        $path === null
        || !function_exists('novamira_sandbox_file_is_disabled')
        || !function_exists('novamira_is_disabled_file')
    ) {
        return false;
    }
    return (
        !novamira_is_disabled_file($path)
        && in_sandbox($path)
        && is_file($path)
        && !novamira_sandbox_file_is_disabled($path)
    );
}

/**
 * What the approvals say about a Novamira Chat call, as for MCP: the model's attention and the fixed checks, the
 * higher one counting against the cutoff. It can refuse the call or make a person decide. In the same conditions as
 * MCP: approvals on, not read mode.
 *
 * The rows counted on the card are information for the person deciding: they never ask anything on their own. The
 * fingerprint stands for what the card showed: an approval is good only while it still matches.
 *
 * @param array<array-key, mixed> $arguments
 * @return array{refused: string, must_ask: bool, why: string, fingerprint: string}
 */
function chat_call_check(string $ability, array $arguments, ?int $attention = null): array
{
    $clear = ['refused' => '', 'must_ask' => false, 'why' => '', 'fingerprint' => ''];
    $policy = call_policy($ability, $arguments);
    if (!chat_call_is_asked_about($policy['confirm'])) {
        return $clear;
    }
    $refused = chat_call_refused($policy, $attention);
    if ($refused !== '') {
        return array_merge($clear, ['refused' => $refused]);
    }
    $args = ['ability_name' => $ability, 'parameters' => $arguments];
    $analysis = ability_check($policy, $args);
    if ($analysis !== null && is_forbidden($analysis)) {
        return array_merge($clear, ['refused' => refusal_text($analysis['rules'])]);
    }
    // The model may raise the check's score, never lower it.
    $score = max($analysis['score'] ?? 0, $policy['score'] ? (int) $attention : 0);
    if ($policy['confirm'] !== 'always' && !chat_call_flagged($analysis, $score)) {
        return $clear;
    }
    $access = access_lines($analysis);
    $details = $analysis !== null ? measured_details($args) : [];
    $measured = array_map(static fn(array $write): string => $write['line'], $details);
    $why = trim(implode(' ', $access) . ' ' . chat_check_reason($analysis['rules'] ?? [], $measured));
    return [
        'refused' => '',
        'must_ask' => true,
        'why' => $why !== '' ? $why : FORCED_REASON,
        'fingerprint' => hash(
            'sha256',
            (string) wp_json_encode([
                $ability,
                $arguments,
                $score,
                $analysis,
                $measured,
                active_file_hash($policy, $arguments),
            ]),
        ),
    ];
}

/**
 * Why a Chat call cannot run at all: the model sent no attention where one is needed. Empty when it can go on to the
 * checks. A request another conversation has waiting does not hold Chat: Chat runs one call of its conversation at a
 * time on its own.
 *
 * @param array{score: bool, confirm: string, check: string, floor: int} $policy
 */
function chat_call_refused(array $policy, ?int $attention): string
{
    return $policy['score'] && $attention === null ? CHAT_ATTENTION_MISSING : '';
}

/** @param array{score: int, rules: list<string>, reject: bool}|null $analysis */
function chat_call_flagged(?array $analysis, int $score): bool
{
    if (requires_confirmation($score, settings()['cutoff'])) {
        return true;
    }
    return (
        $analysis !== null
        && (
            $analysis['reject']
            || access_lines($analysis) !== []
            || moves_money($analysis)
            || deletes_users($analysis)
            || sends_to_new_destination($analysis)
        )
    );
}

const CHAT_ATTENTION_MISSING =
    'This call needs attention: an integer from 0 to 10 saying how much a person should look at it before it runs. '
    . 'Nothing has executed.';

/**
 * Who decides whether a Novamira Chat call waits for a person: "novamira" while approvals are on (the same rules as
 * MCP), "nobody" when an administrator skips approvals, "chat" in read mode, where Chat keeps its own approval.
 */
function chat_approval_mode(): string
{
    if (active()) {
        return 'novamira';
    }
    return covers_work() ? 'nobody' : 'chat';
}

function chat_call_is_asked_about(string $confirm): bool
{
    return active() && $confirm !== 'never' && $confirm !== 'unchecked';
}

/**
 * The refusals no person can lift: code that calls Novamira's own functions could approve itself or take the checks
 * off, and a forbidden WP-CLI command has another way or none. Writing it another way costs nothing.
 *
 * @param array{rules: list<string>, ...} $analysis
 */
function is_forbidden(array $analysis): bool
{
    foreach ($analysis['rules'] as $rule) {
        if (
            in_array($rule, ['refuse:novamira_internals', 'refuse:code_as_text'], strict: true)
            || str_starts_with($rule, 'forbid:')
        ) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<string> $rules
 * @param list<string> $measured
 */
function chat_check_reason(array $rules, array $measured): string
{
    $parts = [];
    // What the check found, in the words the approval card uses; findings with no plain words say nothing.
    $words = array_values(array_unique(array_filter(
        array_map(static fn(string $rule): string => found_rule_words($rule), $rules),
        static fn(string $line): bool => $line !== '',
    )));
    if ($words !== []) {
        $parts[] = implode('. ', $words) . '.';
    }
    if ($measured !== []) {
        $parts[] = 'Counted on the site now: ' . implode(' ', $measured);
    }
    return implode(' ', $parts);
}

/**
 * The content of the file an ability will make run or change: if it changes after a person approved, the approval
 * no longer covers it.
 *
 * @param array{check: string, ...} $policy
 * @param array<array-key, mixed> $arguments
 */
function active_file_hash(array $policy, array $arguments): string
{
    if ($policy['check'] !== 'file_enable' && $policy['check'] !== 'file_write') {
        return '';
    }
    $path = target_path($arguments['path'] ?? null);
    if ($path === null) {
        return '';
    }
    $hash = is_file($path) ? hash_file('sha256', $path) : false;
    return is_string($hash) ? $hash : '';
}
