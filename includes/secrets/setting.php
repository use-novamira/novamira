<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The administrator's switch for hiding secrets from the AI. It is on unless turned off, so sites that never saved
 * it keep hiding them. Every place that hides secrets from what reaches the AI goes through the helpers below.
 */

namespace Novamira\Secrets;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/redact.php';

/** '1' hides secrets, '0' shows results as they are. A missing option means on. */
const SETTING_OPTION = 'novamira_secret_redaction';

/** Whether secrets are hidden from what reaches the AI. */
function enabled(): bool
{
    return get_option(SETTING_OPTION, default_value: '1') !== '0';
}

/** The stored form of a submitted value: only '0' turns hiding off, anything else keeps it on. */
function sanitize_setting(mixed $value): string
{
    return $value === '0' || $value === 0 || $value === false ? '0' : '1';
}

/** redact() while hiding is on, the value unchanged while it is off. */
function hide(mixed $value): mixed
{
    return enabled() ? redact($value) : $value;
}

/** redact_string() while hiding is on, the text unchanged while it is off. */
function hide_string(string $value): string
{
    return enabled() ? redact_string($value) : $value;
}

/** File text as the AI reads it: secrets and dotenv values hidden while hiding is on. */
function hide_file_text(string $path, string $content): string
{
    return enabled() ? redact_file_text($path, redact_text($content)) : $content;
}
