<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

use Novamira\OAuth\Repositories\PendingAuthorizationRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/oauth/repositories/PendingAuthorizationRepository.php';

final class PendingAuthorizationTest extends TestCase
{
    public function testConsentLifetimeIsLongEnoughForAnInteractiveBrowserFlow(): void
    {
        self::assertSame(600, PendingAuthorizationRepository::TTL);
    }

    public function testTheExpiryInstantItselfCountsAsExpired(): void
    {
        $pending = new PendingAuthorizationRepository();

        self::assertTrue($pending->is_expired(gmdate('Y-m-d H:i:s')));
        self::assertTrue($pending->is_expired(gmdate('Y-m-d H:i:s', time() - 1)));
        self::assertFalse($pending->is_expired(gmdate('Y-m-d H:i:s', time() + 60)));
        self::assertTrue($pending->is_expired('not a timestamp'));
    }

    public function testBrowserConsentStateDoesNotDependOnTransients(): void
    {
        $authorize = file_get_contents(__DIR__ . '/../../includes/oauth/endpoints/authorize.php');
        $consent = file_get_contents(__DIR__ . '/../../includes/oauth/consent.php');

        self::assertIsString($authorize);
        self::assertIsString($consent);
        self::assertStringNotContainsString('set_transient(', $authorize);
        self::assertStringNotContainsString('get_transient(', $consent);
        self::assertStringNotContainsString('delete_transient(', $consent);
    }
}
