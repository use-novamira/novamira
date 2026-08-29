<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Design authority policy: which line (if any) the design module adds to the
 * building context and what the design abilities report, depending on the
 * design feature state, the active design, the `novamira_design_authority`
 * filter, and the legacy boolean override. Runs the real
 * includes/design-authority.php and includes/design/bootstrap.php in a bare
 * PHP process with the WordPress surface they touch stubbed in.
 */
final class DesignAuthorityTest extends TestCase
{
    private const DIRECTIVE = 'Before any visual work (building or restyling a page, template, section, or component), load the `novamira-design` skill and follow it.';

    private const ASK_LINE_TAIL = ', which has its own design system, and a Novamira design is active. Before visual work, ask the user once whether the builder\'s design system or the Novamira design is authoritative, then follow that choice for the session. Until Novamira is chosen, use existing builder values and do not add Novamira tokens. If it is chosen, create or reuse each token as a native builder variable, palette colour, global/theme style, or class before referencing it — never paste literals or DESIGN.md names into content, never create a parallel token layer.';

    private const HYBRID_LINE_TAIL = ', whose own design system (theme styles, variables, palettes, classes, components) stays the source of truth for everything it already defines. Consult the active Novamira design (`novamira/get-active-design`) only to fill gaps the builder has no value for, or when the user explicitly asks to apply it; load the `novamira-design` skill in those cases. Create or reuse each design token as a native builder variable, palette colour, global/theme style, or class before referencing it — never paste literals or DESIGN.md names into content, never create a parallel token layer. Existing builder values win any conflict the user has not decided.';

    private const BUILDER_LINE_TAIL = ', whose own design system (theme styles, variables, palettes, classes, components) is the source of truth for visual work. Do not create or activate a Novamira design unless the user asks for one; load the `novamira-design` skill only if the user explicitly asks to apply a Novamira design.';

    private const LEVELS = ['design', 'ask', 'hybrid', 'builder'];

    /** @return array{active: bool, authoritative: bool, authority: string, builder: ?string} */
    private static function fields(string $authority, bool $authoritative, ?string $builder, bool $active): array
    {
        return ['active' => $active, 'authoritative' => $authoritative, 'authority' => $authority, 'builder' => $builder];
    }

    private static function tail(string $level): string
    {
        return match ($level) {
            'ask' => self::ASK_LINE_TAIL,
            'hybrid' => self::HYBRID_LINE_TAIL,
            'builder' => self::BUILDER_LINE_TAIL,
        };
    }

    public function testDisabledFeatureReturnsNoneAndCallsNoFilter(): void
    {
        $result = $this->runScenario([
            'feature_active' => false,
            'active_design' => 'brand',
            'authority_override' => ['level' => 'builder', 'builder' => 'Acme Builder'],
            'authoritative_override' => true,
        ]);

        self::assertSame('none', $result['authority']);
        self::assertFalse($result['authoritative']);
        self::assertNull($result['builder']);
        self::assertSame(['## Building'], $result['lines']);
        self::assertNull($result['filter_context'], 'no authority filter runs while the feature is off');
        self::assertFalse($result['authoritative_called']);
        self::assertContains($result['get_active']['authority'], self::LEVELS);
        self::assertContains($result['check']['authority'], self::LEVELS);
    }

    public function testDefaultIsDesignWithAndWithoutAnActiveDesign(): void
    {
        $withDesign = $this->runScenario(['feature_active' => true, 'active_design' => 'brand']);

        self::assertSame('design', $withDesign['authority']);
        self::assertTrue($withDesign['authoritative']);
        self::assertNull($withDesign['builder']);
        self::assertSame(['## Building', '', self::DIRECTIVE], $withDesign['lines']);
        self::assertSame(
            ['feature_enabled' => true, 'design_active' => true, 'active_design' => 'brand'],
            $withDesign['filter_context'],
        );
        self::assertSame('design', $withDesign['filter_default']);
        self::assertSame(self::fields('design', true, null, true), $withDesign['get_active']);
        self::assertSame(self::fields('design', true, null, true), $withDesign['check']);
        self::assertFalse($withDesign['authoritative_called'], 'the boolean filter is not consulted when nothing hooks it');

        $withoutDesign = $this->runScenario(['feature_active' => true, 'active_design' => '']);

        self::assertSame('design', $withoutDesign['authority']);
        self::assertSame(['## Building', '', self::DIRECTIVE], $withoutDesign['lines']);
        self::assertSame(
            ['feature_enabled' => true, 'design_active' => false, 'active_design' => ''],
            $withoutDesign['filter_context'],
        );
        self::assertSame(self::fields('design', true, null, false), $withoutDesign['get_active']);
        self::assertSame(self::fields('design', true, null, false), $withoutDesign['check']);

        $dangling = $this->runScenario(['feature_active' => true, 'active_design' => 'deleted', 'library' => []]);

        self::assertSame('design', $dangling['authority']);
        self::assertFalse($dangling['filter_context']['design_active']);
        self::assertSame(['## Building', '', self::DIRECTIVE], $dangling['lines']);
        self::assertFalse($dangling['get_active']['active']);
    }

    /** @return iterable<string, array{0: string}> */
    public static function builderLevelProvider(): iterable
    {
        yield 'ask' => ['ask'];
        yield 'hybrid' => ['hybrid'];
        yield 'builder' => ['builder'];
    }

    #[DataProvider('builderLevelProvider')]
    public function testFilterReturningAStringLevelUsesTheGenericWording(string $level): void
    {
        $result = $this->runScenario([
            'feature_active' => true,
            'active_design' => 'brand',
            'authority_override' => $level,
        ]);

        self::assertSame($level, $result['authority']);
        self::assertFalse($result['authoritative']);
        self::assertNull($result['builder']);
        self::assertSame(['## Building', '', 'This site runs the page builder' . self::tail($level)], $result['lines']);
        self::assertStringNotContainsString(self::DIRECTIVE, implode("\n", $result['lines']));
        self::assertSame(self::fields($level, false, null, true), $result['get_active']);
        self::assertSame(self::fields($level, false, null, true), $result['check']);
    }

    #[DataProvider('builderLevelProvider')]
    public function testFilterReturningAnArrayWithALabelNamesTheBuilder(string $level): void
    {
        $result = $this->runScenario([
            'feature_active' => true,
            'active_design' => 'brand',
            'authority_override' => ['level' => $level, 'builder' => '  Acme Builder '],
        ]);

        self::assertSame($level, $result['authority']);
        self::assertSame('Acme Builder', $result['builder']);
        self::assertSame(['## Building', '', 'This site runs Acme Builder' . self::tail($level)], $result['lines']);
        foreach (self::LEVELS as $other) {
            if ($other !== $level && $other !== 'design') {
                self::assertStringNotContainsString(self::tail($other), implode("\n", $result['lines']));
            }
        }
        self::assertSame(self::fields($level, false, 'Acme Builder', true), $result['get_active']);
        self::assertSame(self::fields($level, false, 'Acme Builder', true), $result['check']);
    }

    public function testFilterReturningAnArrayWithoutALabelUsesTheGenericWording(): void
    {
        foreach ([['level' => 'ask'], ['level' => 'ask', 'builder' => ''], ['level' => 'ask', 'builder' => 42]] as $value) {
            $result = $this->runScenario([
                'feature_active' => true,
                'active_design' => 'brand',
                'authority_override' => $value,
            ]);

            self::assertSame('ask', $result['authority']);
            self::assertNull($result['builder']);
            self::assertSame(['## Building', '', 'This site runs the page builder' . self::ASK_LINE_TAIL], $result['lines']);
            self::assertSame(self::fields('ask', false, null, true), $result['get_active']);
        }
    }

    public function testFilterReturningDesignWithALabelKeepsTheDirective(): void
    {
        $result = $this->runScenario([
            'feature_active' => true,
            'active_design' => '',
            'authority_override' => ['level' => 'design', 'builder' => 'Acme Builder'],
        ]);

        self::assertSame('design', $result['authority']);
        self::assertTrue($result['authoritative']);
        self::assertSame('Acme Builder', $result['builder']);
        self::assertSame(['## Building', '', self::DIRECTIVE], $result['lines']);
        self::assertSame(self::fields('design', true, 'Acme Builder', false), $result['check']);
    }

    public function testInvalidFilterValuesKeepDesign(): void
    {
        $invalid = [
            'none',
            'nonsense',
            42,
            null,
            ['level' => 'nonsense', 'builder' => 'Acme Builder'],
            ['level' => 'none', 'builder' => 'Acme Builder'],
            ['builder' => 'Acme Builder'],
            [],
        ];
        foreach ($invalid as $value) {
            $result = $this->runScenario([
                'feature_active' => true,
                'active_design' => 'brand',
                'authority_override' => $value,
            ]);

            self::assertSame('design', $result['authority'], json_encode($value, JSON_THROW_ON_ERROR));
            self::assertNull($result['builder']);
            self::assertSame(['## Building', '', self::DIRECTIVE], $result['lines']);
            self::assertSame(self::fields('design', true, null, true), $result['get_active']);
        }
    }

    public function testLegacyBooleanFilterIsTheLastStep(): void
    {
        $forcedOn = $this->runScenario([
            'feature_active' => true,
            'active_design' => 'brand',
            'authority_override' => ['level' => 'ask', 'builder' => 'Acme Builder'],
            'authoritative_override' => true,
        ]);

        self::assertSame('design', $forcedOn['authority']);
        self::assertTrue($forcedOn['authoritative']);
        self::assertTrue($forcedOn['authoritative_called']);
        self::assertSame(['## Building', '', self::DIRECTIVE], $forcedOn['lines']);
        self::assertSame(self::fields('design', true, 'Acme Builder', true), $forcedOn['get_active']);

        $falseKeepsLevel = $this->runScenario([
            'feature_active' => true,
            'active_design' => 'brand',
            'authority_override' => ['level' => 'hybrid', 'builder' => 'Acme Builder'],
            'authoritative_override' => false,
        ]);

        self::assertSame('hybrid', $falseKeepsLevel['authority'], 'false keeps the level the previous step produced');
        self::assertSame(['## Building', '', 'This site runs Acme Builder' . self::HYBRID_LINE_TAIL], $falseKeepsLevel['lines']);

        $falseOnDesign = $this->runScenario([
            'feature_active' => true,
            'active_design' => 'brand',
            'authoritative_override' => false,
        ]);

        self::assertSame('design', $falseOnDesign['authority'], 'false is a no-op at the design level');
        self::assertSame(['## Building', '', self::DIRECTIVE], $falseOnDesign['lines']);

        $nonBool = $this->runScenario([
            'feature_active' => true,
            'active_design' => 'brand',
            'authority_override' => 'builder',
            'authoritative_override' => 'yes',
        ]);

        self::assertSame('builder', $nonBool['authority'], 'a non-boolean result abstains');
        self::assertSame(['## Building', '', 'This site runs the page builder' . self::BUILDER_LINE_TAIL], $nonBool['lines']);

        $nonBoolOnDesign = $this->runScenario([
            'feature_active' => true,
            'active_design' => 'brand',
            'authoritative_override' => 0,
        ]);

        self::assertSame('design', $nonBoolOnDesign['authority']);
    }

    public function testAbilitySchemasDeclareTheAuthorityFieldsAsRequired(): void
    {
        $result = $this->runScenario(['feature_active' => true, 'active_design' => 'brand']);

        foreach (['get_active', 'check'] as $ability) {
            $schema = $result['schemas'][$ability];
            foreach (['authority', 'authoritative', 'builder'] as $field) {
                self::assertContains($field, $schema['required'], $ability . ' must require ' . $field);
                self::assertArrayHasKey($field, $result[$ability]);
            }
            self::assertSame(self::LEVELS, $schema['properties']['authority']['enum']);
            self::assertSame(['string', 'null'], $schema['properties']['builder']['type']);
        }
        self::assertContains('active', $result['schemas']['get_active']['required']);
        self::assertSame(['ok', 'authority', 'authoritative', 'builder', 'violations'], $result['schemas']['check']['required']);
    }

    public function testFreePluginCarriesNoBuilderNames(): void
    {
        $root = dirname(__DIR__, levels: 2);
        $files = array_merge(
            [$root . '/includes/design-authority.php', $root . '/includes/skills/built-in/novamira-design.md'],
            glob($root . '/includes/design/abilities/*.php') ?: [],
        );
        $pattern = '/\b(bricks|elementor|breakdance|oxygen|divi|etch)\b/i';

        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression($pattern, $content, basename($file) . ' must not name a page builder');
        }
        $policy = (string) file_get_contents($files[0]);
        foreach ([
            'novamira_design_builder_authority',
            'novamira_design_builders_with_own_design_system',
            'novamira_design_active_builder',
            'novamira_design_default_builders',
            'novamira_design_active_theme_template',
            'wp_get_theme',
            'class_exists(',
        ] as $removed) {
            self::assertStringNotContainsString($removed, $policy, 'free must not detect builders: ' . $removed);
        }
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array{authority: string, authoritative: bool, builder: ?string, lines: list<string>, filter_default: mixed, filter_context: mixed, authoritative_called: bool, schemas: array{get_active: array<string, mixed>, check: array<string, mixed>}, get_active: array<string, mixed>, check: array<string, mixed>}
     */
    private function runScenario(array $scenario): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            namespace {
                define('ABSPATH', '/');
                $scenario = json_decode((string) file_get_contents($argv[2]), true);
                $GLOBALS['scenario'] = $scenario;
                $GLOBALS['filters'] = [];
                $GLOBALS['filter_default'] = null;
                $GLOBALS['filter_context'] = null;
                $GLOBALS['authoritative_called'] = false;
                $GLOBALS['abilities'] = [];

                function __(string $text, string $domain = 'default'): string { return $text; }
                function add_action(string $hook, mixed $callback, mixed ...$args): void {}
                function add_filter(string $hook, mixed $callback, mixed ...$args): void {
                    $GLOBALS['filters'][$hook][] = $callback;
                }
                function has_filter(string $hook): bool {
                    if ($hook === 'novamira_design_authoritative' && array_key_exists('authoritative_override', $GLOBALS['scenario'])) {
                        return true;
                    }
                    return isset($GLOBALS['filters'][$hook]);
                }
                function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
                    if ($hook === 'novamira_design_authority') {
                        $GLOBALS['filter_default'] = $value;
                        $GLOBALS['filter_context'] = $args[0] ?? null;
                        if (array_key_exists('authority_override', $GLOBALS['scenario'])) {
                            return $GLOBALS['scenario']['authority_override'];
                        }
                    }
                    if ($hook === 'novamira_design_authoritative') {
                        $GLOBALS['authoritative_called'] = true;
                        if (array_key_exists('authoritative_override', $GLOBALS['scenario'])) {
                            return $GLOBALS['scenario']['authoritative_override'];
                        }
                    }
                    foreach ($GLOBALS['filters'][$hook] ?? [] as $callback) {
                        $value = $callback($value, ...$args);
                    }
                    return $value;
                }
                function get_option(string $name, mixed $default_value = false): mixed {
                    return $name === 'novamira_active_design' ? ($GLOBALS['scenario']['active_design'] ?? '') : $default_value;
                }
                function esc_html(string $text): string { return $text; }
                function esc_attr(string $text): string { return $text; }
                function wp_strip_all_tags(string $text): string { return trim(strip_tags($text)); }
                function sanitize_title(string $text): string { return strtolower($text); }
                class WP_Post {
                    public string $post_title = '';
                    public function __construct(public string $post_name, public string $post_content) {}
                }
                function get_posts(array $args): array {
                    $library = $GLOBALS['scenario']['library'] ?? [$GLOBALS['scenario']['active_design'] ?? ''];
                    $posts = [];
                    foreach ($library as $slug) {
                        if ($slug !== '') { $posts[] = new WP_Post($slug, "---\nname: " . $slug . "\n---\n# " . $slug); }
                    }
                    return $posts;
                }
                function wp_register_ability(string $name, array $args): void { $GLOBALS['abilities'][$name] = $args; }
            }

            namespace Novamira\Features {
                function features(): object {
                    return new class { public function is_active(string $id): bool { return $id === 'novamira/design' && $GLOBALS['scenario']['feature_active']; } };
                }
            }

            namespace {
                require $argv[1] . '/includes/design-authority.php';
                require $argv[1] . '/includes/design/bootstrap.php';

                \Novamira\Design\Abilities\GetActive\register();
                \Novamira\Design\Abilities\Check\register();
                $pick = static function (array $output): array {
                    $fields = array_intersect_key($output, array_flip(['authority', 'authoritative', 'builder', 'active']));
                    ksort($fields);
                    return $fields;
                };
                $resolved = novamira_design_resolve_authority();
                echo json_encode([
                    'authority' => $resolved['level'],
                    'authoritative' => novamira_design_is_authoritative(),
                    'builder' => $resolved['builder'],
                    'lines' => apply_filters('novamira_building_context_lines', ['## Building']),
                    'filter_default' => $GLOBALS['filter_default'],
                    'filter_context' => $GLOBALS['filter_context'],
                    'authoritative_called' => $GLOBALS['authoritative_called'],
                    'schemas' => [
                        'get_active' => $GLOBALS['abilities']['novamira/get-active-design']['output_schema'],
                        'check' => $GLOBALS['abilities']['novamira/check-design']['output_schema'],
                    ],
                    'get_active' => $pick($GLOBALS['abilities']['novamira/get-active-design']['execute_callback']([])),
                    'check' => $pick($GLOBALS['abilities']['novamira/check-design']['execute_callback'](['output' => '<p>x</p>'])),
                ]);
            }
            PHP;
        $scriptFile = tempnam(sys_get_temp_dir(), 'design-authority-');
        $scenarioFile = tempnam(sys_get_temp_dir(), 'design-authority-scenario-');
        self::assertIsString($scriptFile);
        self::assertIsString($scenarioFile);
        file_put_contents($scriptFile, "<?php\n" . $script);
        file_put_contents($scenarioFile, json_encode($scenario, JSON_THROW_ON_ERROR));
        try {
            $output = shell_exec(
                'php ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($scenarioFile) . ' 2>&1',
            );
        } finally {
            unlink($scriptFile);
            unlink($scenarioFile);
        }
        self::assertIsString($output);
        /** @var mixed $decoded */
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, 'Unexpected script output: ' . $output);

        /** @var array{authority: string, authoritative: bool, builder: ?string, lines: list<string>, filter_default: mixed, filter_context: mixed, authoritative_called: bool, schemas: array{get_active: array<string, mixed>, check: array<string, mixed>}, get_active: array<string, mixed>, check: array<string, mixed>} $decoded */
        return $decoded;
    }
}
