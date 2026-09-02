<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Converts invalid feature definitions into activation errors. */
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class ActivationValidator
{
    public function __construct(
        private FeatureRegistry $registry,
    ) {}

    /** @return list<string> */
    public function blockers(string $id): array
    {
        $blockers = [];
        foreach (array_merge([$id], $this->registry->ancestorIds($id)) as $requiredId) {
            $definition = $this->registry->get($requiredId);
            if ($definition === null) {
                $blockers[] = sprintf(__('Unknown feature: %s.', domain: 'novamira'), $requiredId);
                continue;
            }
            if (!$this->registry->isValid($requiredId)) {
                $blockers[] = sprintf(
                    /* translators: %s: feature label */
                    __('%s has an invalid feature definition.', domain: 'novamira'),
                    $definition->label(),
                );
                continue;
            }
        }

        return $blockers;
    }
}
