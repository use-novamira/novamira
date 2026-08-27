<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

const OPTION_STATES = 'novamira_feature_preferences';

// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class StateStore
{
    /** @var array<int, array<string, bool>> */
    private array $cache = [];

    /** @var array<int, bool> */
    private array $needsReconciliation = [];

    public function __construct(
        private FeatureRegistry $registry,
        private Resolver $resolver,
    ) {}

    /** @return array<string, bool> */
    public function all(): array
    {
        $site = $this->siteId();
        if (array_key_exists($site, $this->cache)) {
            return $this->cache[$site];
        }
        /** @var mixed $stored */
        $stored = get_option(OPTION_STATES, default_value: []);
        $raw = is_array($stored) ? $stored : [];
        $states = [];
        /** @var mixed $active */
        foreach ($raw as $id => $active) {
            if (!is_string($id)) {
                continue;
            }
            $definition = $this->registry->get($id);
            if ($definition === null || !$definition->toggleable) {
                continue;
            }
            $states[$id] = in_array($active, [true, 1, '1'], strict: true);
        }
        $this->cache[$site] = $states;
        $this->needsReconciliation[$site] = $states !== $raw;

        return $states;
    }

    public function reconcile(): void
    {
        $site = $this->siteId();
        $states = $this->all();
        $normalized = $this->resolver->normalize($states);
        if (($this->needsReconciliation[$site] ?? false) || $normalized !== $states) {
            update_option(OPTION_STATES, $normalized, autoload: false);
        }
        $this->cache[$site] = $normalized;
        $this->needsReconciliation[$site] = false;
    }

    /** @param array<string, bool> $states */
    public function replace(array $states): void
    {
        $clean = [];
        foreach ($states as $id => $active) {
            $definition = $this->registry->get($id);
            if ($definition === null || !$definition->toggleable) {
                continue;
            }
            $clean[$id] = $active;
        }
        $clean = $this->resolver->normalize($clean);
        update_option(OPTION_STATES, $clean, autoload: false);
        $site = $this->siteId();
        $this->cache[$site] = $clean;
        $this->needsReconciliation[$site] = false;
    }

    private function siteId(): int
    {
        return function_exists('get_current_blog_id') ? get_current_blog_id() : 0;
    }
}
