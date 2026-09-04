<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Public collection API used by Novamira, Pro, and third-party integrations. */
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class Registrar
{
    /** @var array<string, array<array-key, mixed>> */
    private array $manifest = [];

    /** @var list<string> */
    private array $errors = [];

    /** @param array<array-key, mixed> $definition */
    public function register(string $id, array $definition): void
    {
        if (array_key_exists($id, $this->manifest)) {
            $this->errors[] = sprintf('Feature %s is declared more than once.', $id);
            return;
        }
        $this->manifest[$id] = $definition;
    }

    /** @param array<array-key, mixed> $manifest */
    public function register_many(array $manifest): void
    {
        /** @var mixed $definition */
        foreach ($manifest as $id => $definition) {
            if (!is_string($id) || !is_array($definition)) {
                $this->errors[] = 'Feature registrations must use string IDs and array definitions.';
                continue;
            }
            $this->register($id, $definition);
        }
    }

    /** @return array<string, array<array-key, mixed>> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Capability marker for integrations that declare options to import into the central feature store. */
    public function supports_legacy_preference_migration(): bool
    {
        return true;
    }
}
