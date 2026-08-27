<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Runs feature callbacks in dependency-safe order and emits lifecycle events. */
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class FeatureLifecycle
{
    private FeatureBootstrapper $bootstrapper;

    public function __construct(
        private FeatureRegistry $registry,
    ) {
        $this->bootstrapper = new FeatureBootstrapper($registry);
    }

    /** @param array<string, bool> $active */
    public function bootActive(array $active): void
    {
        $this->bootstrapper->bootActive($active);
    }

    /**
     * @param array<string, bool> $before
     * @param array<string, bool> $after
     * @param list<string> $changedIds
     */
    public function apply(array $before, array $after, array $changedIds): void
    {
        foreach ($after as $id => $active) {
            if (!$active || ($before[$id] ?? false)) {
                continue;
            }
            $this->bootstrapper->boot($id);
            $this->announce($id, status: 'active');
        }
        foreach (array_reverse($changedIds) as $id) {
            if (!($before[$id] ?? false) || ($after[$id] ?? false)) {
                continue;
            }
            $definition = $this->registry->get($id);
            if ($definition === null) {
                continue;
            }
            $this->runCallback($definition->deactivateCallback);
            $this->announce($id, status: 'inactive');
        }
    }

    private function runCallback(?string $callback): void
    {
        if ($callback !== null && is_callable($callback)) {
            $callback();
        }
    }

    private function announce(string $id, string $status): void
    {
        do_action('novamira_feature_state_changed', $id, $status);
    }
}
