<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Public facade for feature queries, transitions, and lifecycle. */
// The facade intentionally exposes the complete small Features API while delegating graph, state,
// resolution, and planning work to focused collaborators.
// @mago-expect lint:too-many-methods
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class Manager
{
    private StateStore $states;

    private Resolver $resolver;

    private ActiveStateCache $activeStates;

    private SkillResolver $skills;

    private TransitionPlanner $planner;

    private FeatureLifecycle $lifecycle;

    /** @var array<string, string> */
    private array $runtimeAbilityOwners = [];

    public function __construct(
        private FeatureRegistry $registry,
    ) {
        $this->resolver = new Resolver($registry);
        $this->activeStates = new ActiveStateCache($this->resolver);
        $this->skills = new SkillResolver($registry, $this->activeStates);
        $this->states = new StateStore($registry, $this->resolver);
        $this->planner = new TransitionPlanner(
            $registry,
            new ActivationValidator($registry),
            $this->activeStates,
            $this->skills,
        );
        $this->lifecycle = new FeatureLifecycle($registry);
    }

    /** @return array<string, Definition> */
    public function definitions(): array
    {
        return $this->registry->definitions();
    }

    public function definition(string $id): ?Definition
    {
        return $this->registry->get($id);
    }

    public function feature_for_skill(string $slug): ?Definition
    {
        $features = $this->features_for_skill($slug);

        return $features[0] ?? null;
    }

    /** @return list<Definition> */
    public function features_for_skill(string $slug): array
    {
        return array_values(array_filter(array_map(fn(string $id): ?Definition => $this->definition(
            $id,
        ), $this->registry->features_for_skill($slug))));
    }

    public function feature_for_ability(string $name, string $category = ''): ?Definition
    {
        $id = $this->registry->owner_of_ability($name, $category) ?? $this->runtimeAbilityOwners[$name] ?? null;
        if ($id !== null && $category !== '') {
            $this->runtimeAbilityOwners[$name] = $id;
        }

        return $id !== null ? $this->definition($id) : null;
    }

    public function is_active(string $id): bool
    {
        return $this->activeStates->isActive($id, $this->states->all());
    }

    public function is_skill_active(string $slug): bool
    {
        return $this->skills->isActive($slug, $this->states->all());
    }

    public function is_ability_active(string $name, string $category = ''): bool
    {
        $feature = $this->feature_for_ability($name, $category);

        return $feature === null || $this->is_active($feature->id);
    }

    /** @return list<string> */
    public function inactive_dependencies(string $id): array
    {
        return $this->resolver->inactiveDependencies($id, $this->states->all());
    }

    public function meets_requirements(string $id): bool
    {
        return $this->resolver->meets_requirements($id);
    }

    public function preview_activation(string $id): Transition
    {
        return $this->planner->planActivation($id, $this->states->all());
    }

    public function preview_deactivation(string $id): Transition
    {
        return $this->planner->planDeactivation($id, $this->states->all());
    }

    public function activate(string $id): Transition
    {
        return $this->apply($this->planner->planActivation($id, $this->states->all()));
    }

    public function deactivate(string $id): Transition
    {
        return $this->apply($this->planner->planDeactivation($id, $this->states->all()));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->registry->errors();
    }

    public function boot_active(): void
    {
        $this->states->reconcile();
        $this->lifecycle->bootActive($this->activeStates->all($this->states->all()));
    }

    private function apply(Transition $transition): Transition
    {
        if (!$transition->applied) {
            return $transition;
        }
        $before = $this->activeStates->all($this->states->all());
        $this->states->replace($transition->states);
        $after = $this->activeStates->all($this->states->all());
        $this->lifecycle->apply($before, $after, $transition->features);

        return $transition;
    }
}
