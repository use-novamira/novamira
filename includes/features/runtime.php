<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

/** Collects and finalizes feature registrations exactly once per request. */
// @mago-expect lint:file-name -- includes/ uses lowercase module filenames rather than PSR-4 filenames.
final class Runtime
{
    private static ?Manager $manager = null;

    public static function initialize(): Manager
    {
        if (self::$manager instanceof Manager) {
            return self::$manager;
        }
        $registrar = new Registrar();
        $registrar->register_many(core_manifest());
        do_action('novamira_register_features', $registrar);
        $compiler = new ManifestCompiler();
        $definitions = $compiler->compile($registrar->manifest());
        self::$manager = new Manager(
            new FeatureRegistry($definitions, array_merge($registrar->errors(), $compiler->errors())),
        );

        return self::$manager;
    }

    public static function manager(): Manager
    {
        if (!self::$manager instanceof Manager) {
            throw new \LogicException('Novamira Features is available from plugins_loaded onward.');
        }

        return self::$manager;
    }
}
