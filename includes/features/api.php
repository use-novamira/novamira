<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Features;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/registrar.php';
require_once __DIR__ . '/definition.php';
require_once __DIR__ . '/manifest-compiler.php';
require_once __DIR__ . '/feature-registry.php';
require_once __DIR__ . '/state-store.php';
require_once __DIR__ . '/resolver.php';
require_once __DIR__ . '/active-state-cache.php';
require_once __DIR__ . '/skill-resolver.php';
require_once __DIR__ . '/transition.php';
require_once __DIR__ . '/activation-validator.php';
require_once __DIR__ . '/transition-planner.php';
require_once __DIR__ . '/feature-bootstrapper.php';
require_once __DIR__ . '/feature-lifecycle.php';
require_once __DIR__ . '/manager.php';
require_once __DIR__ . '/runtime.php';

function initialize_features(): Manager
{
    return Runtime::initialize();
}

function features(): Manager
{
    return Runtime::manager();
}
