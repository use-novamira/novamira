<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

// A compiled manifest record deliberately keeps its typed fields together as one immutable-by-convention value.
// @mago-expect lint:too-many-properties
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class Definition
{
    public string $id;

    /** @var 'feature'|'specialization' */
    public string $kind;

    public string $provider;

    /**
     * Display strings stay unresolved until first read: the manifest is compiled on `plugins_loaded`,
     * and calling `__()` that early loads the text domain before `init`.
     *
     * @var string|array{source: string, translate: \Closure(): string}
     */
    private $label;

    /** @var string|array{source: string, translate: \Closure(): string} */
    private $description;

    private ?string $labelText = null;

    private ?string $descriptionText = null;

    public bool $experimental;

    public bool $toggleable;

    public bool $visible;

    public bool $defaultActive;

    /** @var list<string> */
    public array $dependsOn;

    /** @var list<string> */
    public array $skills;

    /** @var list<string> */
    public array $ownedSkills;

    /** @var list<string> */
    public array $sharedSkills;

    /** @var list<string> */
    public array $abilities;

    /** @var list<string> */
    public array $abilityCategories;

    public ?string $bootCallback;

    public ?string $deactivateCallback;

    /**
     * @param array{kind: 'feature'|'specialization', provider: string, label: string|array{source: string, translate: \Closure(): string}, description: string|array{source: string, translate: \Closure(): string}} $identity
     * @param array{experimental: bool, toggleable: bool, visible: bool, default_active: bool, boot: ?string, deactivate: ?string} $behavior
     * @param list<string> $dependencies
     * @param array{owned_skills: list<string>, shared_skills: list<string>, abilities: list<string>, ability_categories: list<string>} $assets
     */
    public function __construct(string $id, array $identity, array $behavior, array $dependencies, array $assets)
    {
        $this->id = $id;
        $this->kind = $identity['kind'];
        $this->provider = $identity['provider'];
        $this->label = $identity['label'];
        $this->description = $identity['description'];
        $this->experimental = $behavior['experimental'];
        $this->toggleable = $behavior['toggleable'];
        $this->visible = $behavior['visible'];
        $this->defaultActive = $behavior['default_active'];
        $this->bootCallback = $behavior['boot'];
        $this->deactivateCallback = $behavior['deactivate'];
        $this->dependsOn = $dependencies;
        $this->ownedSkills = $assets['owned_skills'];
        $this->sharedSkills = $assets['shared_skills'];
        $this->skills = array_values(array_unique(array_merge($this->ownedSkills, $this->sharedSkills)));
        $this->abilities = $assets['abilities'];
        $this->abilityCategories = $assets['ability_categories'];
    }

    public function label(): string
    {
        return self::resolve($this->label, $this->labelText);
    }

    public function description(): string
    {
        return self::resolve($this->description, $this->descriptionText);
    }

    /** Preserves the public label and description reads supported before display strings became deferred. */
    public function __get(string $name): string
    {
        return match ($name) {
            'label' => $this->label(),
            'description' => $this->description(),
            default => throw new \LogicException(sprintf('Unknown feature definition property: %s.', $name)),
        };
    }

    public function __isset(string $name): bool
    {
        return $name === 'label' || $name === 'description';
    }

    /** @param string|array{source: string, translate: \Closure(): string} $value */
    private static function resolve($value, ?string &$resolved): string
    {
        if ($resolved !== null) {
            return $resolved;
        }
        if (is_string($value)) {
            return $resolved = $value;
        }
        if (function_exists('did_action') && \did_action('init') === 0) {
            return $value['source'];
        }

        return $resolved = $value['translate']();
    }
}
