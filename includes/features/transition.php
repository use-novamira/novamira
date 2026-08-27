<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class Transition
{
    public bool $applied;

    public bool $active;

    /** @var list<string> */
    public array $blockers;

    /** @var list<string> */
    public array $features;

    /** @var list<string> */
    public array $skills;

    /** @var list<string> */
    public array $abilities;

    /** @var array<string, bool> */
    public array $states;

    /**
     * @param array{blockers: list<string>, features: list<string>, skills: list<string>, abilities: list<string>} $changes
     * @param array<string, bool> $states
     */
    public function __construct(bool $applied, bool $active, array $changes, array $states)
    {
        $this->applied = $applied;
        $this->active = $active;
        $this->blockers = $changes['blockers'];
        $this->features = $changes['features'];
        $this->skills = $changes['skills'];
        $this->abilities = $changes['abilities'];
        $this->states = $states;
    }
}
