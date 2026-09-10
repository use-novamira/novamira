<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The seven design abilities declare meta.show_in_rest, so the WordPress Abilities REST API exposes
 * them.
 *
 * Their registrars run in a separate process against a local wp_register_ability() stub, and the
 * meta each one passed is what gets asserted. Reading the value PHP produced, rather than the source
 * text that produced it, keeps the assertion true however the array is written. The registrars need
 * nothing beyond the design module itself, so the result does not depend on what is installed on the
 * machine running the suite.
 */
final class DesignAbilityRestVisibilityTest extends TestCase
{
    /** Ability name => the namespace under Novamira\Design\Abilities that registers it. */
    private const DESIGN_ABILITIES = [
        'novamira/list-design-library' => 'ListLibrary',
        'novamira/get-active-design' => 'GetActive',
        'novamira/activate-design' => 'Activate',
        'novamira/save-design' => 'Save',
        'novamira/check-design' => 'Check',
        'novamira/get-design' => 'Get',
        'novamira/delete-design' => 'Delete',
    ];

    public function testDesignAbilitiesDeclareRestVisibility(): void
    {
        $meta = $this->registerDesignAbilities();
        $missing = [];

        foreach (array_keys(self::DESIGN_ABILITIES) as $name) {
            self::assertArrayHasKey($name, $meta, "{$name} was not registered under that name.");
            $ability_meta = $meta[$name];
            if (!is_array($ability_meta) || ($ability_meta['show_in_rest'] ?? null) !== true) {
                $missing[] = $name;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Design abilities missing meta.show_in_rest, so the Abilities REST API does not expose "
                . "them:\n" . implode("\n", $missing),
        );
    }

    /**
     * Run the seven registrars and return the meta each one passed, keyed by ability name.
     *
     * @return array<string, mixed>
     */
    private function registerDesignAbilities(): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', $argv[1] . '/');

            function __(string $text, string $domain = 'default'): string
            {
                return $text;
            }

            $GLOBALS['captured'] = [];
            function wp_register_ability(string $name, array $args): void
            {
                $GLOBALS['captured'][$name] = $args['meta'] ?? null;
            }

            $design = $argv[1] . '/includes/design/';
            require $design . 'contract.php';
            require $design . 'abilities/categories.php';

            foreach (json_decode($argv[2], associative: true) as $file => $namespace) {
                require $design . 'abilities/' . $file . '.php';
                $register = 'Novamira\\Design\\Abilities\\' . $namespace . '\\register';
                $register();
            }

            echo json_encode($GLOBALS['captured'], JSON_THROW_ON_ERROR);
            PHP;

        $files = [];
        foreach (self::DESIGN_ABILITIES as $name => $namespace) {
            $files[substr($name, strlen('novamira/'))] = $namespace;
        }

        $command = [PHP_BINARY, '-r', $script, $root, json_encode($files, JSON_THROW_ON_ERROR)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        self::assertSame(0, $exit_code, "stdout:\n{$stdout}\nstderr:\n{$stderr}");
        self::assertSame('', $stderr, $stderr);

        $decoded = json_decode($stdout, associative: true);
        self::assertIsArray($decoded, $stdout);

        return $decoded;
    }
}
