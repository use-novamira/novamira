<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

// Manifest compilation validates several independent field types in one bounded pass.
// @mago-expect lint:cyclomatic-complexity
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class ManifestCompiler
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * @param array<array-key, mixed> $manifest
     * @return array<string, Definition>
     */
    public function compile(array $manifest): array
    {
        $definitions = [];
        /** @var mixed $raw */
        foreach ($manifest as $key => $raw) {
            $id = is_string($key) ? $key : '';
            $definition = $this->compileDefinition($raw, $id);
            if ($definition === null) {
                continue;
            }
            if (array_key_exists($definition->id, $definitions)) {
                $this->errors[] = sprintf('Feature %s is declared more than once.', $definition->id);
                continue;
            }
            $definitions[$definition->id] = $definition;
        }

        return $definitions;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function compileDefinition(mixed $raw, string $id): ?Definition
    {
        if (!is_array($raw)) {
            $this->errors[] = sprintf('Feature %s must be an array.', $id !== '' ? $id : '(unknown)');
            return null;
        }
        if (preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $id) !== 1) {
            $this->errors[] = sprintf('Invalid feature id: %s.', $id !== '' ? $id : '(empty)');
            return null;
        }

        $skills = $this->skills($raw['skills'] ?? []);

        return new Definition(
            $id,
            [
                'kind' => ($raw['kind'] ?? null) === 'specialization' ? 'specialization' : 'feature',
                'provider' => self::string($raw['provider'] ?? 'Novamira'),
                'label' => $this->text($raw['label'] ?? $id),
                'description' => $this->text($raw['description'] ?? ''),
            ],
            [
                'experimental' => ($raw['experimental'] ?? false) === true,
                'toggleable' => $this->flag($raw, 'toggleable'),
                'visible' => $this->flag($raw, 'visible'),
                'default_active' => $this->flag($raw, 'default_active'),
                'boot' => $this->callbackName($raw['boot'] ?? null),
                'deactivate' => $this->callbackName($raw['deactivate'] ?? null),
                'legacy_options' => $this->stringList($raw['legacy_options'] ?? []),
            ],
            $this->stringList($raw['depends_on'] ?? []),
            [
                'owned_skills' => $skills['owned'],
                'shared_skills' => $skills['shared'],
                'abilities' => $this->stringList($raw['abilities'] ?? []),
                'ability_categories' => $this->stringList($raw['ability_categories'] ?? []),
            ],
        );
    }

    /** @return array{owned: list<string>, shared: list<string>} */
    private function skills(mixed $value): array
    {
        if (!is_array($value)) {
            return ['owned' => [], 'shared' => []];
        }
        $owned = [];
        $shared = [];
        /** @var mixed $relationship */
        foreach ($value as $key => $relationship) {
            $slug = is_string($key) ? self::string($key) : self::string($relationship);
            if ($slug === '') {
                continue;
            }
            if (is_string($key) && $relationship === 'shared') {
                $shared[$slug] = true;
                continue;
            }
            $owned[$slug] = true;
        }

        return ['owned' => array_keys($owned), 'shared' => array_keys($shared)];
    }

    /** @param array<array-key, mixed> $definition */
    private function flag(array $definition, string $key): bool
    {
        return ($definition[$key] ?? true) !== false;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Keeps core display strings deferred so that `__()` runs after `init`, rather than while the
     * manifest compiles on `plugins_loaded`. See Definition::label().
     *
     * @return string|array{source: string, translate: \Closure(): string}
     */
    private function text(mixed $value)
    {
        if (
            !is_array($value)
            || !is_string($value['source'] ?? null)
            || !($value['translate'] ?? null) instanceof \Closure
        ) {
            return self::string($value);
        }

        /** @var \Closure(): string $translate */
        $translate = $value['translate'];

        return [
            'source' => self::string($value['source']),
            'translate' => static fn(): string => self::string($translate()),
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        /** @var mixed $item */
        foreach ($value as $item) {
            $string = self::string($item);
            if ($string !== '') {
                $result[$string] = true;
            }
        }

        return array_keys($result);
    }

    private function callbackName(mixed $value): ?string
    {
        $callback = self::string($value);

        return $callback !== '' ? $callback : null;
    }
}
