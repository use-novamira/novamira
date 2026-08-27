<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

// This is the single decision point for selection, requirements, dependencies, and effective state.
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class Resolver
{
    public function __construct(
        private FeatureRegistry $registry,
    ) {}

    /** @param array<string, bool> $states */
    public function isActiveWithStates(string $id, array $states): bool
    {
        $visiting = [];

        return $this->resolve($id, $states, $visiting);
    }

    /**
     * @param array<string, bool> $states
     * @return array<string, bool>
     */
    public function activeMap(array $states): array
    {
        $active = [];
        foreach (array_keys($this->registry->definitions()) as $id) {
            $visiting = [];
            $active[$id] = $this->resolve($id, $states, $visiting);
        }

        return $active;
    }

    /**
     * Remove latent on-states when a feature cannot be active.
     *
     * @param array<string, bool> $states
     * @return array<string, bool>
     */
    public function normalize(array $states): array
    {
        $active = $this->activeMap($states);
        foreach ($this->registry->definitions() as $id => $definition) {
            if (!$definition->toggleable) {
                unset($states[$id]);
                continue;
            }
            $selected = $states[$id] ?? $definition->defaultActive;
            if (!$selected || !($active[$id] ?? false)) {
                $states[$id] = false;
            }
        }

        return $states;
    }

    /**
     * @param array<string, bool> $states
     * @return list<string>
     */
    public function inactiveDependencies(string $id, array $states): array
    {
        $definition = $this->registry->get($id);
        if ($definition === null) {
            return [];
        }

        return array_values(array_filter(
            $definition->dependsOn,
            fn(string $dependency): bool => !$this->isActiveWithStates($dependency, $states),
        ));
    }

    public function meets_requirements(string $id): bool
    {
        $visiting = [];

        return $this->resolve($id, null, $visiting);
    }

    /**
     * @param array<string, bool>|null $states Null checks requirements without considering selection.
     * @param array<string, true> $visiting
     */
    private function resolve(string $id, ?array $states, array &$visiting): bool
    {
        if (array_key_exists($id, $visiting)) {
            return false;
        }
        $definition = $this->registry->get($id);
        if ($definition === null || !$this->registry->isValid($id)) {
            return false;
        }
        if ($states !== null) {
            $selected = $definition->toggleable ? $states[$id] ?? $definition->defaultActive : true;
            if (!$selected) {
                return false;
            }
        }
        $visiting[$id] = true;
        foreach ($definition->dependsOn as $dependency) {
            if ($this->resolve($dependency, $states, $visiting)) {
                continue;
            }
            unset($visiting[$id]);
            return false;
        }
        unset($visiting[$id]);

        return true;
    }
}
