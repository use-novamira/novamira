<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Confirmations;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The approval behind the call running now, while run_approved() runs it: who approved it and what its card said. The
 * AI change history keeps both on the call. Calls approved together carry the code of the group they share.
 */
final class RunningApproval
{
    /** @var array{by: int, says: string, attention?: int, request?: string, group_request?: string}|null */
    public static ?array $current = null;
}
