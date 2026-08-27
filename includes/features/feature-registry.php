<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Immutable feature definitions plus their dependency and resource indexes. */
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:kan-defect
// @mago-expect lint:too-many-methods
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class FeatureRegistry
{
    /** @var array<string, string> */
    private array $skillOwners = [];

    /** @var array<string, list<string>> */
    private array $skillShares = [];

    /** @var array<string, string> */
    private array $abilityOwners = [];

    /** @var array<string, string> */
    private array $abilityCategoryOwners = [];

    /** @var array<string, list<string>> */
    private array $dependents = [];

    /** @var array<string, true> */
    private array $invalid = [];

    /** @var array<string, 1|2> */
    private array $visitState = [];

    /**
     * @param array<string, Definition> $definitions
     * @param list<string> $errors
     */
    public function __construct(
        private array $definitions,
        private array $errors,
    ) {
        $this->indexResources();
        $this->indexDependencies();
        $this->validateDefinitions();
    }

    /** @return array<string, Definition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function get(string $id): ?Definition
    {
        return $this->definitions[$id] ?? null;
    }

    public function isValid(string $id): bool
    {
        return array_key_exists($id, $this->definitions) && !array_key_exists($id, $this->invalid);
    }

    public function owner_of_skill(string $slug): ?string
    {
        return $this->skillOwners[$slug] ?? null;
    }

    /** @return list<string> */
    public function shared_by_skill(string $slug): array
    {
        return $this->skillShares[$slug] ?? [];
    }

    /** @return list<string> */
    public function features_for_skill(string $slug): array
    {
        $owner = $this->owner_of_skill($slug);

        return $owner !== null ? [$owner] : $this->shared_by_skill($slug);
    }

    public function owner_of_ability(string $name, string $category = ''): ?string
    {
        return $this->abilityOwners[$name] ?? $this->abilityCategoryOwners[$category] ?? null;
    }

    /** @return list<string> */
    public function dependentsOf(string $id): array
    {
        return $this->dependents[$id] ?? [];
    }

    /** @return list<string> */
    public function descendantIds(string $id): array
    {
        $result = [];
        $this->collectDescendants($id, $result);

        return array_keys($result);
    }

    /** @return list<string> */
    public function ancestorIds(string $id): array
    {
        $result = [];
        $this->collectAncestors($id, $result);

        return array_keys($result);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return array_values(array_unique($this->errors));
    }

    private function indexResources(): void
    {
        foreach ($this->definitions as $id => $definition) {
            foreach ($definition->ownedSkills as $slug) {
                $this->indexOwner($this->skillOwners, $slug, $id, 'Skill');
            }
            foreach ($definition->sharedSkills as $slug) {
                $this->skillShares[$slug] ??= [];
                $this->skillShares[$slug][] = $id;
            }
            foreach ($definition->abilities as $name) {
                $this->indexOwner($this->abilityOwners, $name, $id, 'Ability');
            }
            foreach ($definition->abilityCategories as $category) {
                $this->indexOwner($this->abilityCategoryOwners, $category, $id, 'Ability category');
            }
        }
        foreach ($this->skillShares as $slug => $featureIds) {
            $owner = $this->skillOwners[$slug] ?? null;
            if ($owner === null) {
                continue;
            }
            $conflicts = array_values(array_unique(array_merge([$owner], $featureIds)));
            foreach ($conflicts as $id) {
                $this->invalid[$id] = true;
            }
            $this->errors[] = sprintf(
                'Skill %s cannot be both owned by %s and shared by %s.',
                $slug,
                $owner,
                implode(', ', $featureIds),
            );
        }
    }

    private function indexDependencies(): void
    {
        foreach (array_keys($this->definitions) as $id) {
            $this->dependents[$id] = [];
        }
        foreach ($this->definitions as $id => $definition) {
            foreach ($definition->dependsOn as $dependency) {
                if (!array_key_exists($dependency, $this->dependents)) {
                    continue;
                }
                $this->dependents[$dependency][] = $id;
            }
        }
    }

    private function validateDefinitions(): void
    {
        foreach ($this->definitions as $id => $definition) {
            foreach ($definition->dependsOn as $dependency) {
                if (array_key_exists($dependency, $this->definitions)) {
                    continue;
                }
                $this->invalid[$id] = true;
                $this->errors[] = sprintf('%s depends on unknown feature %s.', $id, $dependency);
            }
            foreach ([$definition->bootCallback, $definition->deactivateCallback] as $callback) {
                if ($callback === null || is_callable($callback)) {
                    continue;
                }
                $this->invalid[$id] = true;
                $this->errors[] = sprintf('%s declares unavailable callback %s.', $id, $callback);
            }
        }
        foreach (array_keys($this->definitions) as $id) {
            $this->visit($id, []);
        }
    }

    /** @param array<string, string> $owners */
    private function indexOwner(array &$owners, string $asset, string $id, string $type): void
    {
        $owner = $owners[$asset] ?? null;
        if ($owner === null) {
            $owners[$asset] = $id;
            return;
        }
        $this->invalid[$owner] = true;
        $this->invalid[$id] = true;
        $this->errors[] = sprintf('%s %s is owned by both %s and %s.', $type, $asset, $owner, $id);
    }

    /** @param list<string> $path */
    private function visit(string $id, array $path): void
    {
        if (($this->visitState[$id] ?? null) === 2) {
            return;
        }
        if (($this->visitState[$id] ?? null) === 1) {
            $start = array_search($id, $path, strict: true);
            $cycle = $start === false ? [$id] : array_slice($path, $start);
            foreach ($cycle as $member) {
                $this->invalid[$member] = true;
            }
            $this->errors[] = sprintf('Feature dependency cycle detected: %s.', implode(' → ', $cycle));
            return;
        }
        $definition = $this->get($id);
        if ($definition === null) {
            return;
        }
        $this->visitState[$id] = 1;
        $path[] = $id;
        foreach ($definition->dependsOn as $dependency) {
            if (!array_key_exists($dependency, $this->definitions)) {
                continue;
            }
            $this->visit($dependency, $path);
        }
        $this->visitState[$id] = 2;
    }

    /** @param array<string, true> $result */
    private function collectDescendants(string $id, array &$result): void
    {
        foreach ($this->dependentsOf($id) as $dependent) {
            if (array_key_exists($dependent, $result)) {
                continue;
            }
            $result[$dependent] = true;
            $this->collectDescendants($dependent, $result);
        }
    }

    /** @param array<string, true> $result */
    private function collectAncestors(string $id, array &$result): void
    {
        $definition = $this->get($id);
        if ($definition === null) {
            return;
        }
        foreach ($definition->dependsOn as $dependency) {
            if (array_key_exists($dependency, $result) || !array_key_exists($dependency, $this->definitions)) {
                continue;
            }
            $result[$dependency] = true;
            $this->collectAncestors($dependency, $result);
        }
    }
}
