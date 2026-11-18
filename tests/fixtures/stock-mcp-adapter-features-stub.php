<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/** A feature manager whose only feature owns pro-addon/feature-ability, as a Novamira Pro feature would. */

namespace Novamira\Features;

function features(): object
{
    return new class {
        public function feature_for_ability(string $ability_name, string $category = ''): ?object
        {
            return $ability_name === 'pro-addon/feature-ability' ? (object) ['id' => 'pro-addon/feature'] : null;
        }
    };
}
