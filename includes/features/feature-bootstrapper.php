<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Boots each feature once, after its dependencies. */
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class FeatureBootstrapper
{
    /** @var array<string, true> */
    private array $booted = [];

    public function __construct(
        private FeatureRegistry $registry,
    ) {}

    /** @param array<string, bool> $active */
    public function bootActive(array $active): void
    {
        foreach ($active as $id => $isActive) {
            if (!$isActive) {
                continue;
            }
            $this->boot($id);
        }
    }

    public function boot(string $id): void
    {
        if (array_key_exists($id, $this->booted)) {
            return;
        }
        $definition = $this->registry->get($id);
        if ($definition === null) {
            return;
        }
        foreach ($definition->dependsOn as $dependency) {
            $this->boot($dependency);
        }
        $this->booted[$id] = true;
        if ($definition->bootCallback !== null && is_callable($definition->bootCallback)) {
            ($definition->bootCallback)();
        }
    }
}
