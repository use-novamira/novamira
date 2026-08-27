<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Memoizes the effective feature map for the current preference set. */
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class ActiveStateCache
{
    /** @var array<string, bool>|null */
    private ?array $states = null;

    /** @var array<string, bool>|null */
    private ?array $active = null;

    public function __construct(
        private Resolver $resolver,
    ) {}

    /**
     * @param array<string, bool> $states
     * @return array<string, bool>
     */
    public function all(array $states): array
    {
        if ($this->states !== $states || $this->active === null) {
            $this->states = $states;
            $this->active = $this->resolver->activeMap($states);
        }

        return $this->active;
    }

    /** @param array<string, bool> $states */
    public function isActive(string $id, array $states): bool
    {
        return $this->all($states)[$id] ?? false;
    }
}
