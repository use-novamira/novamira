<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Resolves owned and shared skill availability from feature state. */
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class SkillResolver
{
    public function __construct(
        private FeatureRegistry $registry,
        private ActiveStateCache $features,
    ) {}

    /** @param array<string, bool> $states */
    public function isActive(string $slug, array $states): bool
    {
        $owner = $this->registry->owner_of_skill($slug);
        if ($owner !== null) {
            return $this->features->isActive($owner, $states);
        }
        $sharedBy = $this->registry->shared_by_skill($slug);
        if ($sharedBy === []) {
            return true;
        }
        foreach ($sharedBy as $id) {
            if ($this->features->isActive($id, $states)) {
                return true;
            }
        }

        return false;
    }
}
