<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DESIGN.md Do/Don't guidance parsing, run in a bare PHP process with the
 * handful of WordPress helpers the design parser touches stubbed in.
 */
final class DesignGuidanceParserTest extends TestCase
{
    private const MISSING_WARNING = "No Do's and Don'ts section was found; design checks will only enforce tokens and the universal rules.";

    private const FRONT = "---\nname: G\ncolors:\n  bg: '#f7f7f2'\n  ink: '#171a18'\n  accent: '#0f6b4f'\ntypography:\n  heading: Fraunces\n  body: Karla\n---\n# G\n\n";

    /** @return array<string, mixed> */
    private function inspect(string $body): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');
            function __(string $text, string $domain = 'default'): string { return $text; }
            function esc_html(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
            function esc_attr(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
            function wp_strip_all_tags(string $text): string { return trim(strip_tags($text)); }
            function sanitize_title(string $text): string { return strtolower($text); }

            foreach (['parser', 'tokens', 'preflight', 'markdown', 'contract'] as $file) {
                require $argv[1] . '/includes/design/' . $file . '.php';
            }

            $content = (string) file_get_contents($argv[2]);
            $inspection = \Novamira\Design\Contract\inspect($content);
            $parsed = \Novamira\Design\Markdown\guidance($content, \Novamira\Design\Contract\guidance_titles());
            echo json_encode([
                'guidance' => $inspection['guidance'],
                'warnings' => $inspection['readiness']['warnings'],
                'ready' => $inspection['readiness']['ready'],
                'sync_ready' => $inspection['readiness']['sync_ready'],
                'extract_donts' => \Novamira\Design\Preflight\extract_donts($content),
                'rest' => $parsed['rest'],
                'found' => $parsed['found'],
            ]);
            PHP;
        $document = tempnam(sys_get_temp_dir(), 'design-md-');
        self::assertIsString($document);
        file_put_contents($document, $body);
        $command = sprintf(
            '%s -r %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($root),
            escapeshellarg($document),
        );
        $output = (string) shell_exec($command);
        unlink($document);
        $result = json_decode($output, associative: true);
        self::assertIsArray($result, $output);
        return $result;
    }

    /** @param list<string> $warnings */
    private static function guidanceWarnings(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            static fn(string $warning): bool => str_contains($warning, "Do's and Don'ts section")
                || str_contains($warning, 'no Do/Don\'t items'),
        ));
    }

    public function testBoldLabelsGroupNeutralBullets(): void
    {
        $result = $this->inspect(self::FRONT . "## Do's and Don'ts\n\n**Do**\n- Use the sand palette for backgrounds\n- Keep body text at 18px\n\n**Don't**\n- Mix serif and sans in the same block\n");

        self::assertSame(['Use the sand palette for backgrounds', 'Keep body text at 18px'], $result['guidance']['dos']);
        self::assertSame(['Mix serif and sans in the same block'], $result['guidance']['donts']);
        self::assertSame('', $result['rest'], 'consumed labels must not leak into the prose remainder');
        self::assertSame([], self::guidanceWarnings($result['warnings']));
    }

    public function testSubheadingsGroupNeutralBullets(): void
    {
        $result = $this->inspect(self::FRONT . "## Do's and Don'ts\n\n### Do's\n- Use the sand palette\n1. Keep body text at 18px\n\n### Don'ts\n- Mix serif and sans\n2) Stack shadows\n");

        self::assertSame(['Use the sand palette', 'Keep body text at 18px'], $result['guidance']['dos']);
        self::assertSame(['Mix serif and sans', 'Stack shadows'], $result['guidance']['donts']);
        self::assertSame('', $result['rest']);
    }

    public function testTypographicApostrophesInHeadingAndItems(): void
    {
        $result = $this->inspect(self::FRONT . "## Do\u{2019}s and Don\u{2019}ts\n\n- Don\u{2019}t use gradients\n- Do keep contrast high\n\n### Don\u{2018}ts\n- Fake depth with stacked shadows\n");

        self::assertTrue($result['found']);
        self::assertSame(['Do keep contrast high'], $result['guidance']['dos']);
        self::assertSame(["Don\u{2019}t use gradients", 'Fake depth with stacked shadows'], $result['guidance']['donts']);
    }

    public function testItemWordingOverridesTheGroup(): void
    {
        $result = $this->inspect(self::FRONT . "## Do's and Don'ts\n\n**Do**\n- Never stack shadows\n- Use one accent per view\n\n**Don't:**\n- Always keep contrast high\n- Do not use gradients\n- Mix serif and sans\n");

        self::assertSame(['Use one accent per view', 'Always keep contrast high'], $result['guidance']['dos']);
        self::assertSame(['Never stack shadows', 'Do not use gradients', 'Mix serif and sans'], $result['guidance']['donts']);
    }

    public function testStandaloneDontsSectionAndLabelBulletsWithNestedItems(): void
    {
        $result = $this->inspect(self::FRONT . "## Don'ts\n- Use gradients\n\n## Guidelines\n- **Do**\n  - Keep copy real\n- **Don't**\n  - Ship placeholders\n\n### Typography\n- Karla for body copy\n");

        self::assertSame(['Keep copy real'], $result['guidance']['dos']);
        self::assertSame(['Use gradients', 'Ship placeholders'], $result['guidance']['donts']);
        self::assertStringContainsString('<h4>Typography</h4>', $result['rest'], 'a non-label subheading stays in the prose remainder');
        self::assertStringContainsString('Karla for body copy', $result['rest'], 'a neutral bullet after a non-label subheading is prose again');
        self::assertStringNotContainsString('<strong>Do</strong>', $result['rest']);
    }

    public function testBareGroupTitlesOnlyCountAtLevelTwo(): void
    {
        $front = "---\nname: Dont\ncolors:\n  bg: '#f7f7f2'\n  ink: '#171a18'\n  accent: '#0f6b4f'\ntypography:\n  heading: Fraunces\n  body: Karla\n---\n";

        $titled = $this->inspect($front . "# Don't\n- An introductory bullet under the document title\n\n## Overview\nx\n");
        self::assertFalse($titled['found'], 'a document titled "Don\'t" is not a guidance section');
        self::assertSame(['dos' => [], 'donts' => []], $titled['guidance']);
        self::assertSame([self::MISSING_WARNING], self::guidanceWarnings($titled['warnings']));

        $sectioned = $this->inspect($front . "# Don't\n- An introductory bullet under the document title\n\n## Don'ts\n- Use gradients\n");
        self::assertTrue($sectioned['found']);
        self::assertSame(['Use gradients'], $sectioned['guidance']['donts']);
        self::assertSame([], self::guidanceWarnings($sectioned['warnings']));

        $combined = $this->inspect($front . "# Do's and Don'ts\n- Never use gradients\n");
        self::assertSame(['Never use gradients'], $combined['guidance']['donts'], 'combined titles still match at level 1');
    }

    public function testExtractDontsMatchesContractGuidance(): void
    {
        foreach ([
            "## Do's and Don'ts\n\n**Do**\n- Use the sand palette\n\n**Don't**\n- Mix serif and sans\n- Never ship placeholders\n",
            "## Do's and Don'ts\n\n### Do's\n- Use the sand palette\n\n### Don'ts\n- Mix serif and sans\n",
            "## Do\u{2019}s and Don\u{2019}ts\n- Don\u{2019}t use gradients\n",
            "## Do's and Don'ts\n1. **Do** keep it simple.\n2. **Don't** use gradients.\n",
            "## Overview\nNo guidance here.\n",
        ] as $body) {
            $result = $this->inspect(self::FRONT . $body);
            self::assertSame($result['guidance']['donts'], $result['extract_donts'], $body);
        }
    }

    public function testReadinessWarnsWhenNoGuidanceIsRecognised(): void
    {
        $missing = $this->inspect(self::FRONT . "## Overview\nNo guidance section at all.\n");
        self::assertSame([self::MISSING_WARNING], self::guidanceWarnings($missing['warnings']));
        self::assertTrue($missing['ready']);
        self::assertTrue($missing['sync_ready']);

        $empty = $this->inspect(self::FRONT . "## Do's and Don'ts\n- Sand palette for backgrounds\n- Body text at 18px\n");
        $empty_warnings = self::guidanceWarnings($empty['warnings']);
        self::assertCount(1, $empty_warnings);
        self::assertStringContainsString('no Do/Don\'t items were recognised', $empty_warnings[0]);
        self::assertSame(['dos' => [], 'donts' => []], $empty['guidance']);
        self::assertTrue($empty['ready']);
        self::assertTrue($empty['sync_ready']);

        $recognised = $this->inspect(self::FRONT . "## Do's and Don'ts\n- Do use the accent consistently.\n");
        self::assertSame([], self::guidanceWarnings($recognised['warnings']));
    }
}
