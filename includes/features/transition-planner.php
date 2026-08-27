<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

// Transition planning walks both the dependency graph and the owned-asset index in one pure operation.
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class TransitionPlanner
{
    public function __construct(
        private FeatureRegistry $registry,
        private ActivationValidator $validator,
        private ActiveStateCache $activeStates,
        private SkillResolver $skills,
    ) {}

    /** @param array<string, bool> $states */
    public function planActivation(string $id, array $states): Transition
    {
        $definition = $this->registry->get($id);
        if ($definition === null || !$definition->toggleable) {
            return $this->blocked(true, $states, [__('This feature cannot be changed.', domain: 'novamira')]);
        }
        $blockers = $this->validator->blockers($id);
        if ($blockers !== []) {
            return $this->blocked(true, $states, $blockers);
        }
        $ids = array_merge([$id], $this->registry->ancestorIds($id));

        return $this->applyState($ids, true, $states);
    }

    /** @param array<string, bool> $states */
    public function planDeactivation(string $id, array $states): Transition
    {
        $definition = $this->registry->get($id);
        if ($definition === null || !$definition->toggleable) {
            return $this->blocked(false, $states, [__('This feature cannot be changed.', domain: 'novamira')]);
        }
        $ids = array_merge([$id], $this->registry->descendantIds($id));

        return $this->applyState($ids, false, $states);
    }

    /**
     * @param list<string> $ids
     * @param array<string, bool> $states
     */
    private function applyState(array $ids, bool $active, array $states): Transition
    {
        $before = $states;
        foreach ($ids as $changedId) {
            $changed = $this->registry->get($changedId);
            if ($changed !== null && $changed->toggleable) {
                $states[$changedId] = $active;
            }
        }

        return $this->impact($ids, $active, $before, $states);
    }

    /**
     * @param list<string> $ids
     * @param array<string, bool> $before
     * @param array<string, bool> $after
     */
    private function impact(array $ids, bool $active, array $before, array $after): Transition
    {
        $features = [];
        $candidateSkills = [];
        $candidateAbilities = [];
        foreach ($ids as $id) {
            $definition = $this->registry->get($id);
            if ($definition === null) {
                continue;
            }
            $features[$id] = true;
            foreach ($definition->skills as $skill) {
                $candidateSkills[$skill] = true;
            }
            foreach ($definition->abilities as $ability) {
                $candidateAbilities[$ability] = true;
            }
        }
        $skills = array_values(array_filter(
            array_keys($candidateSkills),
            fn(string $skill): bool => (
                $this->skills->isActive($skill, $before) !== $this->skills->isActive($skill, $after)
            ),
        ));
        $beforeActive = $this->activeStates->all($before);
        $afterActive = $this->activeStates->all($after);
        $abilities = array_values(array_filter(array_keys($candidateAbilities), function (string $ability) use (
            $beforeActive,
            $afterActive,
        ): bool {
            $owner = $this->registry->owner_of_ability($ability);

            return $owner !== null && ($beforeActive[$owner] ?? false) !== ($afterActive[$owner] ?? false);
        }));

        return new Transition(
            true,
            $active,
            [
                'blockers' => [],
                'features' => array_keys($features),
                'skills' => $skills,
                'abilities' => $abilities,
            ],
            $after,
        );
    }

    /**
     * @param array<string, bool> $states
     * @param list<string> $blockers
     */
    private function blocked(bool $active, array $states, array $blockers): Transition
    {
        return new Transition(
            false,
            $active,
            [
                'blockers' => $blockers,
                'features' => [],
                'skills' => [],
                'abilities' => [],
            ],
            $states,
        );
    }
}
