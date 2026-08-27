<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FeatureRegistryTest extends TestCase
{
    public function testRegistrySnapshotOwnershipAndCascadingTransitions(): void
    {
        $result = $this->runRegistryScript(<<<'PHP'
            $features = \Novamira\Features\features();
            $GLOBALS['options']['novamira_feature_preferences'] = [
                'novamira-pro/elementor' => true,
                'novamira-pro/elementor-addon' => true,
            ];
            $before = [
                'parent' => $features->is_active('novamira-pro/elementor'),
                'child' => $features->is_active('novamira-pro/elementor-addon'),
            ];
            $preview = $features->preview_deactivation('novamira-pro/elementor');
            $disabled = $features->deactivate('novamira-pro/elementor');
            $featureRules = ['novamira-pro/elementor-build' => ['disabled' => false]];
            $categoryRules = ['novamira/elementor-runtime' => ['disabled' => false]];
            $standaloneRules = ['third-party/custom' => ['disabled' => false]];
            $afterDisable = [
                'parent' => $features->is_active('novamira-pro/elementor'),
                'child' => $features->is_active('novamira-pro/elementor-addon'),
                'states' => $GLOBALS['options']['novamira_feature_preferences'],
                'category_ability' => $features->is_ability_active('novamira/elementor-runtime', 'elementor'),
            ];
            $features->activate('novamira-pro/elementor');
            $afterParentEnable = [
                'parent' => $features->is_active('novamira-pro/elementor'),
                'child' => $features->is_active('novamira-pro/elementor-addon'),
            ];
            $features->activate('novamira-pro/elementor-addon');
            $infrastructureTransition = $features->deactivate('novamira/infrastructure');
            echo json_encode([
                'before' => $before,
                'preview' => [
                    'features' => $preview->features,
                    'skills' => $preview->skills,
                    'abilities' => $preview->abilities,
                ],
                'disabled_applied' => $disabled->applied,
                'after_disable' => $afterDisable,
                'after_parent_enable' => $afterParentEnable,
                'after_child_enable' => [
                    'parent' => $features->is_active('novamira-pro/elementor'),
                    'child' => $features->is_active('novamira-pro/elementor-addon'),
                ],
                'owners' => [
                    'design_skill' => $features->feature_for_skill('novamira-design')->id,
                    'core_skill' => $features->feature_for_skill('skill-creator')->id,
                    'core_ability' => $features->feature_for_ability('novamira/skill-get')->id,
                    'adapter_ability' => $features->feature_for_ability('novamira-mcp-adapter/execute-ability')->id,
                    'design_prompt' => $features->feature_for_ability('novamira/skill-prompt-novamira-design')->id,
                    'category_ability' => $features->feature_for_ability('novamira/elementor-runtime', 'elementor')->id,
                    'standalone' => $features->feature_for_ability('third-party/custom')?->id,
                ],
                'infrastructure' => [
                    'visible' => $features->definition('novamira/infrastructure')->visible,
                    'toggleable' => $features->definition('novamira/infrastructure')->toggleable,
                    'active' => $features->is_active('novamira/infrastructure'),
                    'disable_applied' => $infrastructureTransition->applied,
                ],
                'queue_owner' => $features->feature_for_ability('novamira/gutenberg-add-pending-change')->id,
                'ability_management' => [
                    'feature_owned' => novamira_ability_can_be_managed_individually('novamira-pro/elementor-build'),
                    'category_owned' => novamira_ability_can_be_managed_individually('novamira/elementor-runtime'),
                    'standalone' => novamira_ability_can_be_managed_individually('third-party/custom'),
                    'feature_rule' => novamira_apply_ability_hub_bulk_action_to_rules(
                        $featureRules,
                        'novamira-pro/elementor-build',
                        'disable',
                    ),
                    'category_rule' => novamira_apply_ability_hub_bulk_action_to_rules(
                        $categoryRules,
                        'novamira/elementor-runtime',
                        'disable',
                    ),
                    'standalone_rule' => novamira_apply_ability_hub_bulk_action_to_rules(
                        $standaloneRules,
                        'third-party/custom',
                        'disable',
                    ),
                ],
                'confirmations' => [
                    'singular' => \Novamira\Features\Admin\feature_confirmation('disable', 'Chat', 0, 1, 1),
                    'cascade' => \Novamira\Features\Admin\feature_confirmation('enable', 'Elementor Addon', 1, 2, 7),
                ],
                'errors' => $features->errors(),
                'filter_calls' => $GLOBALS['feature_filter_calls'],
            ]);
            PHP);

        self::assertSame(['parent' => true, 'child' => true], $result['before']);
        self::assertContains('novamira-pro/elementor', $result['preview']['features']);
        self::assertContains('novamira-pro/elementor-addon', $result['preview']['features']);
        self::assertContains('elementor-build', $result['preview']['skills']);
        self::assertContains('addon-build', $result['preview']['skills']);
        self::assertTrue($result['disabled_applied']);
        self::assertFalse($result['after_disable']['states']['novamira-pro/elementor']);
        self::assertFalse($result['after_disable']['states']['novamira-pro/elementor-addon']);
        self::assertFalse($result['after_disable']['parent']);
        self::assertFalse($result['after_disable']['child']);
        self::assertFalse($result['after_disable']['category_ability']);
        self::assertSame(['parent' => true, 'child' => false], $result['after_parent_enable']);
        self::assertSame(['parent' => true, 'child' => true], $result['after_child_enable']);
        self::assertSame([
            'design_skill' => 'novamira/design',
            'core_skill' => 'novamira/infrastructure',
            'core_ability' => 'novamira/infrastructure',
            'adapter_ability' => 'novamira/infrastructure',
            'design_prompt' => 'novamira/design',
            'category_ability' => 'novamira-pro/elementor',
            'standalone' => null,
        ], $result['owners']);
        self::assertSame([
            'visible' => false,
            'toggleable' => false,
            'active' => true,
            'disable_applied' => false,
        ], $result['infrastructure']);
        self::assertSame('novamira/block-editor-queue', $result['queue_owner']);
        self::assertSame([
            'feature_owned' => false,
            'category_owned' => false,
            'standalone' => true,
            'feature_rule' => ['novamira-pro/elementor-build' => ['disabled' => false]],
            'category_rule' => ['novamira/elementor-runtime' => ['disabled' => false]],
            'standalone_rule' => ['third-party/custom' => ['disabled' => true]],
        ], $result['ability_management']);
        self::assertSame(
            'Disable Chat? This will remove 1 skill and 1 ability from AI agents. Settings and stored data will be preserved.',
            $result['confirmations']['singular'],
        );
        self::assertSame(
            'Enable Elementor Addon? This will also activate 1 required feature and expose 2 skills and 7 abilities to AI agents when AI Abilities are on.',
            $result['confirmations']['cascade'],
        );
        self::assertSame([], $result['errors']);
        self::assertSame(1, $result['filter_calls']);
    }

    public function testLifecycleUsesManifestCallbacks(): void
    {
        $result = $this->runRegistryScript(<<<'PHP'
            $features = \Novamira\Features\features();
            $features->boot_active();
            $features->deactivate('novamira-pro/elementor');
            echo json_encode([
                'booted' => $GLOBALS['booted'],
                'deactivated' => $GLOBALS['deactivated'],
            ]);
            PHP);

        self::assertSame(['elementor', 'addon'], $result['booted']);
        self::assertSame(['addon', 'elementor'], $result['deactivated']);
    }

    public function testInvalidDefinitionCannotBeActivated(): void
    {
        $result = $this->runRegistryScript(
            <<<'PHP'
                $features = \Novamira\Features\features();
                $transition = $features->activate('novamira-pro/invalid');
                echo json_encode([
                    'applied' => $transition->applied,
                    'active' => $features->is_active('novamira-pro/invalid'),
                    'blockers' => $transition->blockers,
                    'errors' => $features->errors(),
                ]);
                PHP,
            includeInvalid: true,
        );

        self::assertFalse($result['applied']);
        self::assertFalse($result['active']);
        self::assertNotSame([], $result['blockers']);
        self::assertNotSame([], $result['errors']);
    }

    public function testStateReadsDoNotPersistUntilBootReconciliation(): void
    {
        $result = $this->runRegistryScript(<<<'PHP'
            $GLOBALS['options']['novamira_feature_preferences'] = ['removed/feature' => true];
            $features = \Novamira\Features\features();
            $activeBeforeBoot = $features->is_active('novamira-pro/elementor');
            $writesBeforeBoot = $GLOBALS['update_calls'];
            $features->boot_active();
            echo json_encode([
                'active_before_boot' => $activeBeforeBoot,
                'writes_before_boot' => $writesBeforeBoot,
                'writes_after_boot' => $GLOBALS['update_calls'],
                'states' => $GLOBALS['options']['novamira_feature_preferences'],
            ]);
            PHP);

        self::assertTrue($result['active_before_boot']);
        self::assertSame(0, $result['writes_before_boot']);
        self::assertSame(1, $result['writes_after_boot']);
        self::assertArrayNotHasKey('removed/feature', $result['states']);
    }

    public function testSharedSkillRemainsActiveUntilItsLastFeatureIsDisabled(): void
    {
        $result = $this->runRegistryScript(<<<'PHP'
            $features = \Novamira\Features\features();
            $firstPreview = $features->preview_deactivation('novamira-pro/shared-one');
            $features->deactivate('novamira-pro/shared-one');
            $activeAfterFirst = $features->is_skill_active('shared-workflow');
            $secondPreview = $features->preview_deactivation('novamira-pro/shared-two');
            $features->deactivate('novamira-pro/shared-two');
            echo json_encode([
                'managers' => array_map(
                    static fn($feature) => $feature->id,
                    $features->features_for_skill('shared-workflow'),
                ),
                'first_impact' => $firstPreview->skills,
                'active_after_first' => $activeAfterFirst,
                'second_impact' => $secondPreview->skills,
                'active_after_second' => $features->is_skill_active('shared-workflow'),
            ]);
            PHP);

        self::assertSame(['novamira-pro/shared-one', 'novamira-pro/shared-two'], $result['managers']);
        self::assertNotContains('shared-workflow', $result['first_impact']);
        self::assertTrue($result['active_after_first']);
        self::assertContains('shared-workflow', $result['second_impact']);
        self::assertFalse($result['active_after_second']);
    }

    /** @return array<string, mixed> */
    private function runRegistryScript(string $body, bool $includeInvalid = false): array
    {
        $root = dirname(__DIR__, levels: 2);
        $bootstrap = <<<'PHP'
            define('ABSPATH', '/');
            $GLOBALS['options'] = [];
            $GLOBALS['feature_filter_calls'] = 0;
            $GLOBALS['booted'] = [];
            $GLOBALS['deactivated'] = [];
            $GLOBALS['update_calls'] = 0;
            $GLOBALS['include_invalid_feature'] = __INCLUDE_INVALID__;
            $GLOBALS['blog_id'] = 1;

            class WP_Ability {
                public function __construct(private string $name, private string $category = '') {}
                public function get_name(): string { return $this->name; }
                public function get_category(): string { return $this->category; }
            }

            function __(string $text, string $domain = 'default'): string { return $text; }
            function _n(string $single, string $plural, int $number, string $domain = 'default'): string {
                return $number === 1 ? $single : $plural;
            }
            function do_action(string $hook, mixed ...$args): void {
                if ($hook !== 'novamira_register_features') { return; }
                $GLOBALS['feature_filter_calls']++;
                $args[0]->register_many(test_feature_manifest());
            }
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
            function test_feature_manifest(): array {
                $value = [];
                $value['novamira-pro/elementor'] = [
                    'kind' => 'specialization',
                    'provider' => 'Novamira Pro',
                    'label' => 'Elementor',
                    'default_active' => true,
                    'depends_on' => [],
                    'skills' => ['elementor-build'],
                    'abilities' => ['novamira-pro/elementor-build'],
                    'ability_categories' => ['elementor'],
                    'boot' => 'test_elementor_boot',
                    'deactivate' => 'test_elementor_deactivate',
                ];
                $value['novamira-pro/elementor-addon'] = [
                    'kind' => 'specialization',
                    'provider' => 'Novamira Pro',
                    'label' => 'Elementor Addon',
                    'default_active' => true,
                    'depends_on' => ['novamira-pro/elementor'],
                    'skills' => ['addon-build'],
                    'abilities' => ['novamira-pro/addon-build'],
                    'boot' => 'test_addon_boot',
                    'deactivate' => 'test_addon_deactivate',
                ];
                $value['novamira-pro/shared-one'] = [
                    'kind' => 'specialization',
                    'provider' => 'Novamira Pro',
                    'label' => 'Shared One',
                    'default_active' => true,
                    'depends_on' => [],
                    'skills' => ['shared-workflow' => 'shared'],
                    'abilities' => [],
                ];
                $value['novamira-pro/shared-two'] = [
                    'kind' => 'specialization',
                    'provider' => 'Novamira Pro',
                    'label' => 'Shared Two',
                    'default_active' => true,
                    'depends_on' => [],
                    'skills' => ['shared-workflow' => 'shared'],
                    'abilities' => [],
                ];
                if ($GLOBALS['include_invalid_feature']) {
                    $value['novamira-pro/invalid'] = [
                        'kind' => 'specialization',
                        'provider' => 'Novamira Pro',
                        'label' => 'Invalid',
                        'default_active' => true,
                        'depends_on' => ['novamira-pro/unknown'],
                        'skills' => [],
                        'abilities' => [],
                    ];
                }
                return $value;
            }
            function test_elementor_boot(): void { $GLOBALS['booted'][] = 'elementor'; }
            function test_addon_boot(): void { $GLOBALS['booted'][] = 'addon'; }
            function test_elementor_deactivate(): void { $GLOBALS['deactivated'][] = 'elementor'; }
            function test_addon_deactivate(): void { $GLOBALS['deactivated'][] = 'addon'; }
            function novamira_boot_design_feature(): void {}
            function novamira_boot_chat_feature(): void {}
            function novamira_boot_visual_feature(): void {}
            function novamira_boot_block_editor_queue_feature(): void {}
            function novamira_deactivate_block_editor_queue_feature(): void {}
            function get_option(string $name, mixed $default_value = false): mixed {
                return $GLOBALS['options'][$name] ?? $default_value;
            }
            function update_option(string $name, mixed $value, bool $autoload = true): bool {
                $GLOBALS['update_calls']++;
                $GLOBALS['options'][$name] = $value;
                return true;
            }
            function get_site_option(string $name, mixed $default_value = false): mixed { return $default_value; }
            function get_current_blog_id(): int { return $GLOBALS['blog_id']; }
            function is_multisite(): bool { return false; }
            function wp_get_ability(string $name): ?WP_Ability {
                return match ($name) {
                    'novamira-pro/elementor-build' => new WP_Ability($name, 'elementor'),
                    'novamira/elementor-runtime' => new WP_Ability($name, 'elementor'),
                    'third-party/custom' => new WP_Ability($name),
                    default => null,
                };
            }
            require $argv[1] . '/includes/features/api.php';
            \Novamira\Features\initialize_features();
            require $argv[1] . '/includes/features/admin.php';
            require $argv[1] . '/includes/helpers.php';
            require $argv[1] . '/includes/admin-page.php';
            PHP;
        $bootstrap = str_replace('__INCLUDE_INVALID__', $includeInvalid ? 'true' : 'false', $bootstrap);
        $script = $bootstrap . "\n" . $body;
        $command = sprintf(
            '%s -r %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($root),
        );
        $output = (string) shell_exec($command);
        $decoded = json_decode($output, associative: true);
        self::assertIsArray($decoded, $output);

        return $decoded;
    }
}
