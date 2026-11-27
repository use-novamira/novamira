<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Secrets;

if (!defined('ABSPATH')) {
    exit();
}

const REDACTED = '[redacted]';

/** What the AI reads in place of a password set during its call. It starts as every hidden value does. */
const PASSWORD_REDACTED = '[redacted] (a password set in this call)';

/**
 * Passwords set in this PHP request, in clear as WordPress hands them to its hooks: what the AI reads back of its own
 * call shows none of them. Each is listed raw and JSON-escaped, longest first. Shorter ones would hide ordinary text.
 *
 * @return list<string>
 */
function set_passwords(?string $password = null): array
{
    /** @var list<string> $passwords */
    static $passwords = [];
    if ($password === null || strlen($password) < 8 || is_password_hash($password)) {
        return $passwords;
    }
    $passwords[] = $password;
    $encoded = json_encode($password, JSON_UNESCAPED_UNICODE);
    if (is_string($encoded) && strlen($encoded) > 2) {
        $passwords[] = substr($encoded, offset: 1, length: -1);
    }
    $passwords = array_values(array_unique($passwords));
    usort($passwords, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    return $passwords;
}

/** A stored hash rather than a password: it is hidden as one already. */
function is_password_hash(string $value): bool
{
    return preg_match('/^\$(?:[PH]\$|wp\$|2[aby]\$|argon2)/', $value) === 1;
}

/**
 * Secrets this site actually holds: the wp-config keys and salts, the database password, and Novamira's own
 * OAuth signing and encryption keys. Each is listed raw and JSON-escaped, because stored results embed JSON.
 *
 * @return list<string>
 */
function site_secrets(): array
{
    $values = [];
    foreach ([
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
        'DB_PASSWORD',
    ] as $constant) {
        /** @var mixed $value */
        $value = defined($constant) ? constant($constant) : null;
        if (is_string($value)) {
            $values[] = $value;
        }
    }
    foreach (['novamira_oauth_private_key', 'novamira_oauth_encryption_key'] as $option) {
        /** @var mixed $value */
        $value = get_option($option, default_value: '');
        if (is_string($value)) {
            $values[] = $value;
        }
    }
    $secrets = [];
    foreach ($values as $value) {
        // Short values (an empty or placeholder DB password) would redact ordinary text.
        if (strlen($value) < 8) {
            continue;
        }
        $secrets[] = $value;
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && strlen($encoded) > 2) {
            $secrets[] = substr($encoded, offset: 1, length: -1);
        }
    }
    $secrets = array_values(array_unique($secrets));
    // Longest first, so a secret that contains another one is replaced whole.
    usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    return $secrets;
}

/** @return list<string> Shapes of secrets whose exact value the site does not know. */
function secret_patterns(): array
{
    return [
        // Password hashes: phpass, WordPress bcrypt, bcrypt, argon2.
        '/\$[PH]\$[.\/0-9A-Za-z]{31}/',
        '/\$wp\$2[aby]\$\d{2}\$[.\/0-9A-Za-z]{53}/',
        '/\$2[aby]\$\d{2}\$[.\/0-9A-Za-z]{53}/',
        '/\$argon2(?:i|d|id)\$[^\s"\'<>]+/',
        // PEM private keys.
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        // Truncated PEM output: full base64 lines followed by at most one shorter final line.
        // Escaped newlines and slashes also occur in JSON-encoded inspection results. A fragment before
        // horizontal whitespace needs 32 body characters, to avoid consuming a following ordinary word.
        <<<'REGEX'
            ~-----BEGIN[ ][A-Z ]*PRIVATE[ ]KEY-----(?:\r?\n|\\r\\n|\\n)
            (?:[A-Za-z0-9+/=]|\\/){64,}+
            (?:(?:\r?\n|\\r\\n|\\n)(?:[A-Za-z0-9+/=]|\\/){64,}+)*+
            (?:
                (?:\r?\n|\\r\\n|\\n)
                (?:
                    (?:[A-Za-z0-9+/=]|\\/){32,63}+(?=[ \t])
                    |(?:[A-Za-z0-9+/=]|\\/){1,63}+(?=\r?\n|\\r\\n|\\n|$|["'])
                )
            )?~x
            REGEX,
        // Common API keys: Stripe, AWS, GitHub, Slack, OpenAI and Anthropic.
        '/\b[rs]k_(?:live|test)_[0-9A-Za-z]{16,}/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\bgh[pousr]_[0-9A-Za-z]{36,}/',
        '/\bxox[abprs]-[0-9A-Za-z-]{10,}/',
        '/\bsk-(?:ant-|proj-)?[0-9A-Za-z_-]{20,}/',
    ];
}

/**
 * Replace secrets in stored data. Used on requests once they can no longer execute, so the log keeps what
 * happened without keeping keys, salts or password hashes for 15 days. It catches the site's own secrets
 * exactly and common secret shapes by pattern, traversing arrays and plain database row objects.
 *
 * @param array<int, true> $seen Object identities on the current traversal path.
 */
function redact(mixed $value, array $seen = []): mixed
{
    if (is_string($value)) {
        return redact_string($value);
    }
    // Plain database rows are data; leave objects with application behavior alone.
    if (is_object($value) && $value::class === \stdClass::class) {
        return redact_row($value, $seen);
    }
    if (!is_array($value)) {
        return $value;
    }
    $result = [];
    // @mago-expect analysis:mixed-assignment -- Stored arguments and results are arbitrary JSON-like data.
    foreach ($value as $key => $item) {
        $result[$key] = redact($item, $seen);
    }
    return $result;
}

/**
 * Clone a plain database row before redacting its property values. A cycle keeps its original reference.
 *
 * @param array<int, true> $seen
 */
function redact_row(\stdClass $value, array $seen): \stdClass
{
    $id = spl_object_id($value);
    if ($seen[$id] ?? false) {
        return $value;
    }
    $seen[$id] = true;
    $result = clone $value;
    // @mago-expect analysis:mixed-assignment -- Row properties are arbitrary JSON-like data.
    foreach (get_object_vars($value) as $key => $item) {
        // @mago-expect analysis:string-member-selector -- Only existing public stdClass properties are copied.
        $result->{(string) $key} = redact($item, $seen);
    }
    return $result;
}

function redact_string(string $value): string
{
    $redacted = redact_text($value);
    if ($redacted !== $value || !str_contains($value, '\\/')) {
        return $redacted;
    }
    // JSON text escapes slashes, which hides hashes and keys from the patterns. Unescaping a slash keeps
    // the JSON valid, so it is done only when that is what reveals a secret.
    $unescaped = str_replace('\\/', replace: '/', subject: $value);
    $redacted = redact_text($unescaped);
    return $redacted !== $unescaped ? $redacted : $value;
}

function redact_text(string $value): string
{
    $value = str_replace(set_passwords(), PASSWORD_REDACTED, $value);
    $value = str_replace(site_secrets(), REDACTED, $value);
    foreach (secret_patterns() as $pattern) {
        $replaced = preg_replace($pattern, REDACTED, $value);
        if (is_string($replaced)) {
            $value = $replaced;
        }
    }
    return $value;
}

/** True for dotenv files, whose every value is assumed to be a credential. */
function is_env_file(string $path): bool
{
    $name = strtolower(basename($path));
    return $name === '.env' || str_starts_with($name, '.env.') || str_ends_with($name, '.env');
}

/** Text read from a file, with dotenv values redacted. */
function redact_file_text(string $path, string $content): string
{
    return is_env_file($path) ? redact_env($content) : $content;
}

/**
 * Replace every value in dotenv text, keeping the variable names so the agent still sees what is configured.
 */
function redact_env(string $content): string
{
    $redacted = preg_replace(
        '/^(\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_.-]*\s*=\s*)(?!\s*$).*$/m',
        '$1' . REDACTED,
        $content,
    );
    return is_string($redacted) ? $redacted : $content;
}

/**
 * Whether a write would put back a line read earlier with its secret hidden: a line holding "[redacted]" that
 * matches an existing line of the file with a real value in its place (the database password in wp-config.php,
 * an API key). Writing it would replace that value and break the site. Other text holding the word, such as
 * documentation or a test, overwrites nothing and is allowed.
 */
function introduces_placeholder(string $before, string $after): bool
{
    if (!str_contains($after, REDACTED)) {
        return false;
    }
    $existing = array_flip(explode("\n", $before));
    foreach (explode("\n", $after) as $line) {
        if (!str_contains($line, REDACTED) || array_key_exists($line, $existing)) {
            continue;
        }
        $pattern = str_replace(
            preg_quote(REDACTED, delimiter: '/'),
            replace: '.+?',
            subject: preg_quote(rtrim($line, characters: "\r"), delimiter: '/'),
        );
        // A pattern PHP could not run counts as a match: refusing a write is the safe way to be wrong.
        if (preg_match('/^' . $pattern . '\r?$/m', $before) !== 0) {
            return true;
        }
    }
    return false;
}

/** The refusal returned by file abilities that would write placeholders back. */
function placeholder_error(): \WP_Error
{
    return new \WP_Error(
        'redacted_placeholder',
        'The new content contains "'
        . REDACTED
        . '", the placeholder Novamira shows in place of secrets. Writing it would replace the real value and could break the site. Nothing was written. Change only the lines you need with edit-file, keeping the secret lines out of old_string and new_string.',
        ['status' => 400],
    );
}
