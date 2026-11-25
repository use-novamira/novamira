<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\GhostMode;

if (!defined('ABSPATH')) {
    exit();
}

function render_bypass_notice(): void
{
    if (!bypass_active() || !\novamira_current_user_can_manage() || !is_active(read_policy())) {
        return;
    }

    wp_admin_notice(
        esc_html__(
            'Ghost Mode is bypassed: NOVAMIRA_GHOST_MODE_BYPASS is set in wp-config.php, so every administrator sees Novamira. Remove the constant to hide Novamira again from administrators not listed under Visible to.',
            domain: 'novamira',
        ),
        ['type' => 'warning', 'dismissible' => false],
    );
}
