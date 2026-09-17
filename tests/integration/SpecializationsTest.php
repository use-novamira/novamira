<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SpecializationsTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function detect(
        array $options,
        string $template,
        string $stylesheet,
        mixed $response = null,
        bool $refresh = false,
        bool $schedule = false,
        array $scheduled = [],
    ): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');
            define('NOVAMIRA_VERSION', 'test');
            define('DAY_IN_SECONDS', 86400);

            $input = json_decode((string) file_get_contents('php://stdin'), associative: true);
            $GLOBALS['options'] = $input['options'];

            function get_option(string $option, mixed $default_value = false): mixed
            {
                return $GLOBALS['options'][$option] ?? $default_value;
            }

            function get_site_option(string $option, mixed $default_value = false): mixed
            {
                return $GLOBALS['options'][$option] ?? $default_value;
            }

            function is_multisite(): bool
            {
                return (bool) ($GLOBALS['options']['__multisite'] ?? false);
            }

            function wp_get_theme(): object
            {
                return new class ($GLOBALS['theme']) {
                    /** @param array{template: string, stylesheet: string} $theme */
                    public function __construct(private array $theme)
                    {
                    }

                    public function get_template(): string
                    {
                        return $this->theme['template'];
                    }

                    public function get_stylesheet(): string
                    {
                        return $this->theme['stylesheet'];
                    }
                };
            }

            function add_action(string $hook, callable|string $callback): bool
            {
                return true;
            }

            function add_filter(string $hook, callable|string $callback): bool
            {
                return true;
            }

            function __(string $text, string $domain = ''): string
            {
                return $text;
            }

            function wp_next_scheduled(string $hook): false|int
            {
                return $GLOBALS['scheduled'][$hook] ?? false;
            }

            function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
            {
                $GLOBALS['scheduled'][$hook] = $timestamp;
                $GLOBALS['cron'][] = ['scheduled', $hook, $timestamp];
                return true;
            }

            function wp_clear_scheduled_hook(string $hook): int
            {
                unset($GLOBALS['scheduled'][$hook]);
                $GLOBALS['cron'][] = ['cleared', $hook];
                return 1;
            }

            function update_option(string $option, mixed $value, bool $autoload = true): bool
            {
                $GLOBALS['options'][$option] = $value;
                $GLOBALS['written'][] = $option;
                return true;
            }

            function wp_remote_get(string $url, array $args = []): mixed
            {
                return $GLOBALS['response'];
            }

            function is_wp_error(mixed $thing): bool
            {
                return is_array($thing) && ($thing['__error'] ?? false) === true;
            }

            function wp_remote_retrieve_response_code(mixed $response): int
            {
                return is_array($response) ? (int) ($response['code'] ?? 0) : 0;
            }

            function wp_remote_retrieve_body(mixed $response): string
            {
                return is_array($response) ? (string) ($response['body'] ?? '') : '';
            }

            function home_url(): string
            {
                return 'https://example.test';
            }

            function novamira_pro_is_active(): bool
            {
                return (bool) ($GLOBALS['options']['__pro_active'] ?? false);
            }

            $GLOBALS['theme'] = $input['theme'];
            $GLOBALS['response'] = $input['response'];
            $GLOBALS['written'] = [];
            $GLOBALS['cron'] = [];
            $GLOBALS['scheduled'] = $input['scheduled'];

            require $argv[1] . '/includes/specializations.php';

            if ($input['refresh']) {
                novamira_specializations_refresh();
            }

            if ($input['schedule']) {
                novamira_schedule_specializations_refresh();
            }

            $folders = novamira_active_folders();
            $active = [];
            foreach (novamira_specializations() as $specialization) {
                if (novamira_specialization_is_active($specialization, $folders)) {
                    $active[] = $specialization['label'];
                }
            }

            echo json_encode([
                'folders' => array_keys($folders),
                'active' => $active,
                'count' => count(novamira_specializations()),
                'written' => $GLOBALS['written'],
                'stored' => $GLOBALS['options']['novamira_specializations'] ?? null,
                'cron' => $GLOBALS['cron'],
            ]);
            PHP;

        $command = sprintf(
            '%s -r %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($root),
        );

        $payload = json_encode([
            'options' => $options,
            'theme' => ['template' => $template, 'stylesheet' => $stylesheet],
            'response' => $response,
            'refresh' => $refresh,
            'schedule' => $schedule,
            'scheduled' => (object) $scheduled,
        ]);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], (string) $payload);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $result = json_decode($output, associative: true);
        self::assertIsArray($result, $output);
        return $result;
    }

    /** @return array<string, mixed> */
    private function stored(mixed ...$entries): array
    {
        return ['schema_version' => 1, 'specializations' => array_values($entries)];
    }

    public function testNamesTheSpecializationsCoveringWhatIsActive(): void
    {
        $result = $this->detect(
            [
                'active_plugins' => ['elementor/elementor.php', 'akismet/akismet.php'],
                'novamira_specializations' => $this->stored(
                    ['name' => 'elementor', 'label' => 'Elementor', 'plugins' => ['elementor', 'elementor-pro']],
                    ['name' => 'acf', 'label' => 'Advanced Custom Fields', 'plugins' => ['advanced-custom-fields']],
                ),
            ],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
        );

        self::assertSame(['Elementor'], $result['active']);
    }

    public function testAChildThemeIsRecognizedByItsParent(): void
    {
        $result = $this->detect(
            [
                'active_plugins' => [],
                'novamira_specializations' => $this->stored(
                    ['name' => 'astra', 'label' => 'Astra', 'plugins' => ['astra-addon'], 'themes' => ['astra']],
                ),
            ],
            template: 'astra',
            stylesheet: 'astra-child',
        );

        self::assertSame(['Astra'], $result['active']);
        self::assertContains('astra-child', $result['folders']);
    }

    public function testNetworkActivatedPluginsCount(): void
    {
        $result = $this->detect(
            [
                '__multisite' => true,
                'active_plugins' => [],
                'active_sitewide_plugins' => ['woocommerce/woocommerce.php' => 1700000000],
                'novamira_specializations' => $this->stored(
                    ['name' => 'woocommerce', 'label' => 'WooCommerce', 'plugins' => ['woocommerce']],
                ),
            ],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
        );

        self::assertSame(['WooCommerce'], $result['active']);
    }

    public function testMalformedEntriesAreDroppedRatherThanTrusted(): void
    {
        $result = $this->detect(
            [
                'active_plugins' => ['elementor/elementor.php'],
                'novamira_specializations' => $this->stored(
                    'not an entry',
                    ['label' => 'No name'],
                    ['name' => 'empty', 'label' => ''],
                    ['name' => 'elementor', 'label' => 'Elementor', 'plugins' => ['elementor', '../../evil', 42]],
                ),
            ],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
        );

        self::assertSame(1, $result['count']);
        self::assertSame(['Elementor'], $result['active']);
    }

    public function testADocumentFromAFutureFormatIsRefusedWhole(): void
    {
        $result = $this->detect(
            [
                'active_plugins' => ['elementor/elementor.php'],
                'novamira_specializations' => [
                    'schema_version' => 2,
                    'specializations' => [
                        ['name' => 'elementor', 'label' => 'Elementor', 'plugins' => ['elementor']],
                    ],
                ],
            ],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
        );

        self::assertSame(0, $result['count']);
        self::assertSame([], $result['active']);
    }

    public function testAFailedFetchLeavesTheStoredListAlone(): void
    {
        $stored = $this->stored(
            ['name' => 'elementor', 'label' => 'Elementor', 'plugins' => ['elementor']],
        );

        foreach (
            [
                'server unreachable' => ['__error' => true],
                'server error' => ['code' => 503, 'body' => 'down for maintenance'],
                'not the file' => ['code' => 200, 'body' => '<html>captive portal</html>'],
                'empty document' => ['code' => 200, 'body' => '{"schema_version":1,"specializations":[]}'],
            ] as $case => $response
        ) {
            $result = $this->detect(
                [
                    'active_plugins' => ['elementor/elementor.php'],
                    'novamira_specializations' => $stored,
                ],
                template: 'twentytwentyfour',
                stylesheet: 'twentytwentyfour',
                response: $response,
                refresh: true,
            );

            self::assertSame([], $result['written'], $case);
            self::assertSame($stored, $result['stored'], $case);
            self::assertSame(['Elementor'], $result['active'], $case);
        }
    }

    public function testASuccessfulFetchReplacesTheStoredList(): void
    {
        $result = $this->detect(
            ['active_plugins' => ['elementor/elementor.php']],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
            response: [
                'code' => 200,
                'body' => (string) json_encode(
                    $this->stored(['name' => 'elementor', 'label' => 'Elementor', 'plugins' => ['elementor']]),
                ),
            ],
            refresh: true,
        );

        self::assertSame(['novamira_specializations'], $result['written']);
    }

    public function testNoFetchHappensWhereProIsRunning(): void
    {
        $result = $this->detect(
            ['active_plugins' => ['elementor/elementor.php'], '__pro_active' => true],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
            response: [
                'code' => 200,
                'body' => (string) json_encode(
                    $this->stored(['name' => 'elementor', 'label' => 'Elementor', 'plugins' => ['elementor']]),
                ),
            ],
            refresh: true,
        );

        self::assertSame([], $result['written']);
    }

    public function testTheEventIsRemovedWhereProIsRunningAndComesBackWithoutIt(): void
    {
        $withPro = $this->detect(
            ['active_plugins' => [], '__pro_active' => true],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
            schedule: true,
            scheduled: ['novamira_specializations_refresh' => 1800000000],
        );

        self::assertSame([['cleared', 'novamira_specializations_refresh']], $withPro['cron']);

        $withoutPro = $this->detect(
            ['active_plugins' => []],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
            schedule: true,
        );

        self::assertSame('scheduled', $withoutPro['cron'][0][0]);
        self::assertSame('novamira_specializations_refresh', $withoutPro['cron'][0][1]);
    }

    public function testNothingStoredMeansNothingRecognized(): void
    {
        $result = $this->detect(
            ['active_plugins' => ['elementor/elementor.php']],
            template: 'twentytwentyfour',
            stylesheet: 'twentytwentyfour',
        );

        self::assertSame(0, $result['count']);
        self::assertSame([], $result['active']);
    }
}
