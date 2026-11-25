<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * What the AI reads about this site, after the scale: the instructions an administrator writes for approvals, and the
 * abilities set to always ask, so the AI rates code that reaches the same effect the same way.
 */

namespace Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

/** The instructions. Its name starts with novamira_, so the AI's own code cannot write it without asking. */
const INSTRUCTIONS_OPTION = 'novamira_confirmation_instructions';

const INSTRUCTIONS_MAX = 2000;

function approval_instructions(): string
{
    /** @var mixed $stored */
    $stored = get_option(INSTRUCTIONS_OPTION, default_value: '');
    return is_string($stored) ? $stored : '';
}

function save_approval_instructions(string $text): void
{
    $text = trim(sanitize_textarea_field($text));
    update_option(INSTRUCTIONS_OPTION, mb_substr($text, start: 0, length: INSTRUCTIONS_MAX), autoload: false);
}

/** The abilities set to always ask, with what their description says they do. */
function always_ask_instructions(): string
{
    $lines = [];
    foreach (ability_choices() as $name => $choice) {
        if ($choice !== 'yes') {
            continue;
        }
        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;
        $description = $ability instanceof \WP_Ability ? first_sentence($ability->get_description()) : '';
        $lines[] = '- ' . $name . ($description === '' ? '' : ': ' . $description);
    }
    if ($lines === []) {
        return '';
    }
    return (
        "These abilities always ask (read an ability's info for its full description):\n"
        . implode("\n", $lines)
        . "\nIf code you run or write (PHP, WP-CLI, files) reaches the same effect as one of them, rate it 10."
    );
}

/** The first sentence of an ability's description, at most 160 characters: the rules stay short to read. */
function first_sentence(string $description): string
{
    $text = trim((string) preg_replace('/\s+/', replacement: ' ', subject: $description));
    $match = [];
    $end = preg_match('/^.+?[.!?](?=\s|$)/u', $text, $match) === 1 ? $match[0] : $text;
    return mb_strimwidth($end, start: 0, width: 160, trim_marker: '...', encoding: 'UTF-8');
}

/** Everything about this site the AI reads with the scale; empty when there is nothing. */
function site_approval_instructions(): string
{
    $parts = array_filter([
        always_ask_instructions(),
        approval_instructions() === ''
            ? ''
            : "This site's administrator wants to approve these actions, however they are done, with an ability or with code you run or write (PHP, WP-CLI, files). Rate any call that does one of them 10:\n"
            . approval_instructions(),
    ]);
    return implode("\n", $parts);
}

/**
 * A three-letter code for the site's rules as they are now, empty when there are none. The AI gets it with the rules
 * and sends it back with every call it rates: a call with an old code, or none, is sent back with the rules as they
 * are now, so no call runs rated without them.
 */
function rules_code(): string
{
    $rules = site_approval_instructions();
    if ($rules === '') {
        return '';
    }
    $number = (int) hexdec(substr(hash('sha256', $rules), offset: 0, length: 8)) % 17_576;
    return chr(97 + intdiv($number, num2: 676)) . chr(97 + (intdiv($number, num2: 26) % 26)) . chr(97 + ($number % 26));
}

/** What the AI is told to do with the code, next to the rules; empty when the site has none. */
function rules_code_line(): string
{
    $code = rules_code();
    if ($code === '') {
        return '';
    }
    return (
        "Rules code: {$code}. Send it as review.rules with every call you rate, or inside the input as "
        . "\"novamira_review\": {\"rules\": \"{$code}\"}. When the rules change, a call with the old code is sent back "
        . 'with the new rules and their code.'
    );
}

/**
 * The code a call sent, beside its parameters or inside them.
 *
 * @param array<array-key, mixed> $args
 */
function sent_rules_code(array $args): string
{
    $review = is_array($args['review'] ?? null) ? $args['review'] : [];
    if (is_string($review['rules'] ?? null)) {
        return strtolower(trim($review['rules']));
    }
    $parameters = is_array($args['parameters'] ?? null) ? $args['parameters'] : [];
    /** @var array<array-key, mixed> $inside */
    $inside = is_array($parameters[REVIEW_IN_PARAMETERS] ?? null) ? $parameters[REVIEW_IN_PARAMETERS] : [];
    return is_string($inside['rules'] ?? null) ? strtolower(trim($inside['rules'])) : '';
}

/** The rules code a call of this request sent and matched, for the AI change history. Empty when none was checked. */
function verified_rules_code(?string $code = null): string
{
    static $verified = '';
    if ($code !== null) {
        $verified = $code;
    }
    return $verified;
}

/**
 * The answer to a rated call sent without the code of the site's rules as they are now: the rules and their code,
 * before anything runs. Empty when the code matches or the site has no rules.
 *
 * @param array<array-key, mixed> $args
 */
function rules_refusal(array $args): string
{
    $code = rules_code();
    if ($code === '' || !active()) {
        return '';
    }
    if (hash_equals($code, sent_rules_code($args))) {
        verified_rules_code($code);
        return '';
    }
    return (
        "This call did not send the code of this site's approval rules as they are now: they changed since you read "
        . "them, or you did not have them. They replace any earlier version you read:\n"
        . site_approval_instructions()
        . "\n"
        . rules_code_line()
        . "\nRate this call again with them and send it again with the code. Nothing has executed."
    );
}
