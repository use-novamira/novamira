<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The page is rendered in a child process: it needs WordPress functions and request globals that
 * must not leak into the rest of the suite.
 */
final class DeviceVerificationRenderTest extends TestCase
{
    private const CODE_INPUT = 'name="user_code"';

    private const LOOKUP_NONCE = 'novamira_oauth_device_lookup';

    public function testPlainPageAsksForTheCode(): void
    {
        self::assertStringContainsString(self::CODE_INPUT, self::render(''));
    }

    public function testApprovedOutcomeIsShownWithoutTheCodeForm(): void
    {
        $html = self::render('approved');

        self::assertStringContainsString('Device authorized. You can return to the application.', $html);
        self::assertStringNotContainsString(self::CODE_INPUT, $html);
    }

    public function testDeniedOutcomeIsShownWithoutTheCodeForm(): void
    {
        $html = self::render('denied');

        self::assertStringContainsString('Device authorization denied.', $html);
        self::assertStringNotContainsString(self::CODE_INPUT, $html);
    }

    public function testRejectedCodeOutcomesStillOfferTheCodeForm(): void
    {
        self::assertStringContainsString(self::CODE_INPUT, self::render('settled'));
        self::assertStringContainsString(self::CODE_INPUT, self::render('unknown'));
    }

    public function testFreshLookupIsStillProcessedUnderAStaleOutcome(): void
    {
        $html = self::render('approved', 'ABCD-EFGH', self::LOOKUP_NONCE);

        self::assertStringContainsString('That code is not valid or has expired.', $html);
        self::assertStringContainsString(self::CODE_INPUT, $html);
    }

    public function testBlankLookupUnderAStaleOutcomeStillOffersTheCodeForm(): void
    {
        foreach (['approved', 'denied'] as $device_result) {
            foreach (['', '--'] as $posted_code) {
                self::assertStringContainsString(
                    self::CODE_INPUT,
                    self::render($device_result, $posted_code, self::LOOKUP_NONCE),
                );
            }
        }
    }

    /**
     * Renders a GET, or a lookup POST when $posted_code is given, and asserts exactly which nonce
     * actions the page checked.
     */
    private static function render(string $device_result, ?string $posted_code = null, string ...$nonces): string
    {
        $command = sprintf(
            '%s %s %s%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../fixtures/device-verification-render-runner.php'),
            escapeshellarg($device_result),
            $posted_code === null ? '' : ' ' . escapeshellarg($posted_code),
        );
        $lines = explode("\n", trim((string) shell_exec($command)));
        $checked = [];
        while ($lines !== [] && str_starts_with((string) end($lines), 'nonce: ')) {
            array_unshift($checked, substr((string) array_pop($lines), strlen('nonce: ')));
        }
        $html = implode("\n", $lines);
        self::assertStringEndsWith('</div>', $html, $html);
        self::assertSame($nonces, $checked);
        return $html;
    }
}
