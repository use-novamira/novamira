<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for ReadModePolicyTest: php read-mode-policy-runner.php <plugin root> <scenario>.
 */

namespace Novamira\Features {
    /** Every Ability is active and standalone, so only the hub rules and read mode decide. */
    function features(): object
    {
        return new class {
            public function is_ability_active(string $ability_name, string $category = ''): bool
            {
                return true;
            }

            public function feature_for_ability(string $ability_name, string $category = ''): ?object
            {
                return null;
            }

            public function features_for_skill(string $slug): array
            {
                return [];
            }

            public function is_skill_active(string $slug): bool
            {
                return true;
            }
        };
    }
}

namespace Novamira\Skills\Sources {
    /** The skills a scenario puts in $GLOBALS['test_skills']; any other slug falls back to the previous owner. */
    function find(string $slug): ?array
    {
        return $GLOBALS['test_skills'][$slug] ?? null;
    }

    /**
     * A skill source, recording every time it is consulted (which runs the sources' loaders). Like the
     * real one, it offers only the skills that declare read_mode while read mode is on.
     */
    function discoverable(string $mode): array
    {
        $GLOBALS['skill_source_loads'][] = $mode;
        $skills = [
            [
                'slug' => 'demo-skill',
                'name' => 'Demo Skill',
                'description' => 'A demo skill.',
                'content' => 'Demo skill body.',
                'source' => 'test',
                'enable_prompt' => true,
                'enable_agentic' => true,
            ],
            [
                'slug' => 'read-skill',
                'name' => 'Read Skill',
                'description' => 'A skill that only reads.',
                'content' => 'Read skill body.',
                'source' => 'test',
                'enable_prompt' => false,
                'enable_agentic' => true,
                'read_mode' => true,
            ],
        ];

        return array_values(array_filter(
            $skills,
            static fn(array $skill): bool => (
                ($mode === 'agentic' || $skill['enable_prompt'])
                && (!\novamira_is_read_mode() || ($skill['read_mode'] ?? false) === true)
            ),
        ));
    }
}

namespace {
    if ($argc !== 3) {
        exit(2);
    }

    [, $root, $scenario] = $argv;

    require __DIR__ . '/read-mode-stubs.php';
    require __DIR__ . '/options-wpdb.php';
    require $root . '/includes/helpers.php';
    require $root . '/includes/read-mode/policy.php';

    if (!in_array($scenario, ['no-pro', 'hooks', 'annotations', 'policy-broken-filter', 'policy-frozen'], true)) {
        test_install_pro_stand_in();
    }

    const TEST_ALL_ABILITIES = [
        'core/get-environment-info',
        'core/get-site-info',
        'novamira-mcp-adapter/discover-abilities',
        'novamira-mcp-adapter/get-ability-info',
        'novamira-mcp-adapter/execute-ability',
        'novamira/activate-design',
        'novamira/agent-context',
        'novamira/check-design',
        'novamira/create-admin-access-link',
        'novamira/create-upload-link',
        'novamira/delete-design',
        'novamira/delete-file',
        'novamira/disable-file',
        'novamira/edit-file',
        'novamira/enable-file',
        'novamira/execute-php',
        'novamira/get-active-design',
        'novamira/get-design',
        'novamira/get-wp-cli-job',
        'novamira/gutenberg-add-pending-change',
        'novamira/gutenberg-create-pending-batch',
        'novamira/gutenberg-delete-pending-batch',
        'novamira/gutenberg-delete-pending-change',
        'novamira/gutenberg-enable-batch-finalization',
        'novamira/gutenberg-get-block-type',
        'novamira/gutenberg-get-content',
        'novamira/gutenberg-get-finalization-url',
        'novamira/gutenberg-get-finalizer-runtime',
        'novamira/gutenberg-get-pending-batch',
        'novamira/gutenberg-list-block-types',
        'novamira/gutenberg-list-pending-batches',
        'novamira/gutenberg-write-content',
        'novamira/list-design-library',
        'novamira/list-directory',
        'novamira/read-file',
        'novamira/run-wp-cli',
        'novamira/save-design',
        'novamira/skill-delete',
        'novamira/skill-edit',
        'novamira/skill-get',
        'novamira/skill-prompt-skill-creator',
        'novamira/skill-write',
        'novamira/write-file',
        'novamira/get-object',
        'novamira/query-objects',
        'novamira/get-options',
        'novamira/list-options',
        'novamira/describe-schema',
        'novamira/read-query',
    ];

    /** The WordPress pieces the server instructions read, defined once per process. */
    function test_define_environment(): void
    {
        if (class_exists('WP_User', false)) {
            return;
        }
        class WP_User
        {
            public int $ID = 7;
        }
        class WP_Theme
        {
            public function get(string $key): string
            {
                return 'Twenty Test';
            }

            public function get_template(): string
            {
                return 'twentytest';
            }

            public function get_stylesheet(): string
            {
                return 'twentytest';
            }
        }
        function wp_get_current_user(): WP_User
        {
            return new WP_User();
        }
        function get_bloginfo(string $show = ''): string
        {
            return '6.9.4';
        }
        function get_locale(): string
        {
            return 'en_US';
        }
        function wp_get_theme(): WP_Theme
        {
            return new WP_Theme();
        }
    }

    function test_register_all(): void
    {
        foreach (TEST_ALL_ABILITIES as $name) {
            test_register($name, meta: ['mcp' => ['public' => true, 'type' => 'tool']]);
        }
        // Annotated read-only by its provider, which must not be enough to survive read mode.
        test_register('acme/delete-everything', 'acme', [
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ]);
        test_register('acme/report', 'acme', ['mcp' => ['public' => true]]);
    }

    function test_policy_result(): array
    {
        novamira_apply_ability_policy();
        return ['registered' => array_keys(wp_get_abilities()), 'unregistered' => $GLOBALS['unregistered']];
    }

    $GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
    $GLOBALS['options']['novamira_ai_abilities_domain'] = 'example.test';

    $result = match ($scenario) {
        'upstream-on', 'upstream-declared-on' => (static function () use ($root, $scenario): array {
            require __DIR__ . '/read-mode-rest-list-stubs.php';
            require $root . '/vendor/novamira/mcp-adapter/autoload.php';
            require $root . '/includes/ability-metadata.php';
            require $root . '/includes/abilities/discover-abilities.php';
            require $root . '/includes/admin-page.php';
            require $root . '/includes/mcp-mirror-server.php';
            foreach (['Abilities/McpAbilityExposure', 'Abilities/McpAbilityHelperTrait', 'Domain/Utils/AbilityArgumentNormalizer', 'Abilities/DiscoverAbilitiesAbility', 'Abilities/GetAbilityInfoAbility', 'Abilities/ExecuteAbilityAbility'] as $file) {
                require_once $root . '/vendor/wordpress/mcp-adapter/includes/' . $file . '.php';
            }
            test_read_mode(true);
            // Novamira's fence on the unprefixed adapter's built-ins runs before read mode's guard.
            add_filter('wp_register_ability_args', 'novamira_fence_stock_mcp_adapter_ability', PHP_INT_MAX - 1, 2);
            novamira_register_read_mode_hooks();
            if ($scenario === 'upstream-declared-on') {
                add_filter('novamira_read_mode_allow_declared_abilities', static fn(): bool => true);
            }
            $names = [
                'mcp-adapter/discover-abilities',
                'mcp-adapter/get-ability-info',
                'mcp-adapter/execute-ability',
            ];
            foreach ([...$names, 'mcp-adapter/other-tool', 'acme/write-thing', 'novamira/read-file'] as $name) {
                $meta = [
                    'show_in_rest' => true,
                    'mcp' => ['public' => true],
                    'annotations' => ['readonly' => in_array($name, $names, true)],
                ];
                if (in_array($name, $names, true)) {
                    // The adapter's own class registers it, as the plugin bundling the adapter does.
                    [NOVAMIRA_STOCK_MCP_ADAPTER_ABILITY_CLASSES[$name], 'register']();
                } else {
                    test_register($name, meta: $meta);
                }
            }
            $GLOBALS['options']['novamira_ability_rules'] = array_fill_keys($names, ['disabled' => true]);
            novamira_apply_ability_policy();
            $result = ['registered' => array_keys(wp_get_abilities()), 'surfaces' => []];
            foreach ([true, false] as $manager) {
                $GLOBALS['can_manage'] = $manager;
                novamira_read_mode_rest_sweep(null, [], new WP_REST_Request());
                $request = new WP_REST_Request(['page' => 1, 'per_page' => 100, 'category' => null]);
                $response = novamira_filter_rest_ability_list_response(
                    new WP_REST_Response(array_map(
                        static fn(WP_Ability $a): array => ['name' => $a->get_name()],
                        array_values(wp_get_abilities()),
                    )),
                    new WP_REST_Abilities_V1_List_Controller(),
                    $request,
                );
                $surface = [
                    'discover' => array_column(novamira_execute_discover_abilities()['abilities'], 'name'),
                    'rest' => array_column($response->get_data(), 'name'),
                    'mirror' => novamira_discover_public_abilities('tool'),
                ];
                foreach ($names as $name) {
                    $surface['items'][$name] = test_export(novamira_filter_rest_ability_item_response(
                        new WP_REST_Response(['name' => $name]), new WP_REST_Request(['name' => $name]),
                    ));
                    $surface['info'][$name] = test_export(novamira_get_ability_info_permission([
                        'ability_name' => $name,
                    ]));
                    $surface['execute'][$name] = test_export(novamira_execute_ability_permission([
                        'ability_name' => $name,
                    ]));
                    $surface['hub'][$name] = wp_has_ability($name)
                        ? novamira_build_registered_ability_row(wp_get_ability($name), []) : null;
                }
                $result['surfaces'][$manager ? 'manager' : 'reader'] = $surface;
            }
            $result['permitted'] = array_map('novamira_read_mode_permits_ability', $names);
            $result['declared'] = novamira_read_mode_declared_abilities();
            // Another plugin's own Ability registered under each name: the real policy removes it.
            $genuine = array_map('wp_get_ability', array_combine($names, $names));
            foreach ($names as $name) {
                wp_unregister_ability($name);
                wp_register_ability($name, [
                    'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true], 'annotations' => ['readonly' => true]],
                    'permission_callback' => static fn(): bool => true,
                    'execute_callback' => static fn(): string => 'fake executed',
                ]);
            }
            novamira_apply_ability_policy();
            $result['fakes_registered'] = array_values(array_intersect($names, array_keys(wp_get_abilities())));
            $GLOBALS['abilities'] = array_merge($GLOBALS['abilities'], $genuine);
            novamira_apply_ability_policy();
            $result['genuine_registered_again'] = array_values(array_intersect($names, array_keys(wp_get_abilities())));
            foreach ($names as $name) {
                $impostor = new class($name) extends WP_Ability {};
                $GLOBALS['abilities'][$name] = $impostor;
                novamira_apply_ability_policy_rule($impostor, [$name => ['disabled' => true]]);
                $result['helper_impostors'][$name] = wp_has_ability($name);
            }
            return $result;
        })(),
        'policy-off' => (static function (): array {
            $GLOBALS['options']['novamira_ability_rules'] = ['acme/report' => ['disabled' => true]];
            test_register_all();
            return test_policy_result();
        })(),
        'policy-on' => (static function (): array {
            test_read_mode(true);
            test_register_all();
            return test_policy_result() + ['allowed' => novamira_read_mode_allowed_abilities()];
        })(),
        'policy-on-with-rule' => (static function (): array {
            test_read_mode(true);
            // One of the abilities Pro adds is one the administrator chooses; the other is required.
            add_filter('novamira_read_mode_abilities', static function (array $abilities): array {
                $abilities['novamira/get-object']['optional'] = true;
                return $abilities;
            }, 20);
            $GLOBALS['options']['novamira_ability_rules'] = [
                'novamira/get-object' => ['disabled' => true],
                'novamira/read-query' => ['disabled' => true],
            ];
            test_register_all();
            return test_policy_result();
        })(),
        'policy-screen-off' => (static function (): array {
            $GLOBALS['is_admin'] = true;
            $_GET['page'] = 'novamira-abilities';
            $GLOBALS['options']['novamira_ability_rules'] = ['acme/report' => ['disabled' => true]];
            test_register_all();
            return test_policy_result();
        })(),
        'policy-screen-on' => (static function (): array {
            $GLOBALS['is_admin'] = true;
            $_GET['page'] = 'novamira-features';
            test_read_mode(true);
            $GLOBALS['options']['novamira_ability_rules'] = ['novamira/read-query' => ['disabled' => true]];
            test_register_all();
            return test_policy_result();
        })(),
        'policy-filter' => (static function (): array {
            test_read_mode(true);
            // The single filter adds an ability with its callbacks, removes one, and carries entries that are not usable.
            add_filter('novamira_read_mode_abilities', static function (array $abilities): array {
                unset($abilities['novamira/read-query']);
                $abilities['acme/report'] = [
                    'execute' => 'test_pro_read_execute',
                    'permission' => 'novamira_permission_callback',
                    'guidance' => "A report.\n<b>Two</b> lines.",
                ];
                $abilities['novamira/execute-php'] = ['execute' => 'novamira_execute_php'];
                $abilities['acme/no-callbacks'] = 'not an entry';
                $abilities[42] = ['execute' => 'a', 'permission' => 'b'];

                return $abilities;
            });
            test_register_all();
            return test_policy_result() + [
                'allowed' => novamira_read_mode_allowed_abilities(),
                'instructions' => novamira_read_mode_instruction_lines(),
            ];
        })(),
        'declared-on', 'declared-off' => (static function () use ($scenario): array {
            test_read_mode(true);
            if ($scenario === 'declared-on') {
                add_filter('novamira_read_mode_allow_declared_abilities', static fn(): bool => true);
            }
            add_filter('wp_register_ability_args', 'novamira_read_mode_guard_ability_args', PHP_INT_MAX, 2);
            test_register_all();

            $register = static function (string $name, ?bool $readonly) : void {
                wp_register_ability($name, [
                    'description' => 'About ' . $name . '.',
                    'permission_callback' => static fn(): bool => true,
                    'execute_callback' => static fn(): array => [],
                    'meta' => [
                        'mcp' => ['public' => true, 'type' => 'tool'],
                        'annotations' => $readonly === null ? [] : ['readonly' => $readonly],
                    ],
                ]);
            };
            $register('acme/declared-read', true);
            $register('acme/declared-write', false);
            $register('acme/not-declared', null);
            // Novamira's own namespaces never count as declared, whatever the annotation says.
            $register('novamira/look-alike', true);
            $register('novamira-extra/look-alike', true);

            $guard = static function (string $name): ?bool {
                $ability = wp_get_ability($name);
                $callbacks = $ability === null ? null : novamira_read_mode_ability_callbacks($ability);
                $permission = $callbacks['permission'] ?? null;

                return is_callable($permission) ? (bool) $permission() : null;
            };
            $names = ['acme/declared-read', 'acme/declared-write', 'acme/not-declared', 'novamira/look-alike'];
            $before = [];
            foreach ($names as $name) {
                $before[$name] = $guard($name);
            }
            $result = test_policy_result();
            $after = [];
            foreach ($names as $name) {
                $after[$name] = $guard($name);
            }

            return $result + [
                'allowed' => novamira_read_mode_allowed_abilities(),
                'allows' => [
                    'acme/declared-read' => novamira_read_mode_allows_ability('acme/declared-read'),
                    'acme/declared-write' => novamira_read_mode_allows_ability('acme/declared-write'),
                    'novamira/look-alike' => novamira_read_mode_allows_ability('novamira/look-alike'),
                ],
                'declared' => novamira_read_mode_declared_abilities(),
                'candidates' => array_keys(novamira_read_mode_declared_candidates()),
                'guard_before' => $before,
                'guard_after' => $after,
            ];
        })(),
        'policy-broken-filter' => (static function (): array {
            test_read_mode(true);
            add_filter('novamira_read_mode_abilities', static fn(): string => 'not a list');
            test_register_all();
            return test_policy_result() + ['allowed' => novamira_read_mode_allowed_abilities()];
        })(),
        'policy-frozen' => (static function (): array {
            test_read_mode(true);
            function did_action(string $hook): int
            {
                return 1;
            }
            function doing_action(string $hook): bool
            {
                return false;
            }
            $before = novamira_read_mode_allowed_abilities();
            // A filter attached after the first check, once all plugins have loaded, has no effect.
            add_filter('novamira_read_mode_abilities', static function (array $abilities): array {
                $abilities['acme/late'] = ['execute' => 'late_execute', 'permission' => 'late_permission'];
                return $abilities;
            });
            return ['before' => $before, 'after' => novamira_read_mode_allowed_abilities()];
        })(),
        'no-pro' => (static function (): array {
            test_read_mode(true);
            test_register_all();
            return test_policy_result() + [
                'allowed' => novamira_read_mode_allowed_abilities(),
                'instructions' => novamira_read_mode_instruction_lines(),
            ];
        })(),
        'mcp-tools' => (static function (): array {
            test_register_all();
            // The adapter's own meta-tools carry no MCP metadata.
            test_register('novamira-mcp-adapter/get-ability-info', 'novamira-mcp-adapter');
            test_register('novamira/list-design-library', 'design-system', ['mcp' => ['public' => true, 'type' => 'tool']]);
            test_register('novamira/skill-prompt-skill-creator', 'skill', ['mcp' => ['public' => true, 'type' => 'prompt']]);
            unset($GLOBALS['abilities']['novamira/describe-schema']);
            $trio = [
                'novamira-mcp-adapter/discover-abilities',
                'novamira-mcp-adapter/get-ability-info',
                'novamira-mcp-adapter/execute-ability',
            ];
            $discover = static fn(string $type): array => ['discovered/' . $type];
            $off = novamira_mcp_server_components($trio, $discover);
            $off_config = novamira_read_mode_default_server_config(['tools' => $trio, 'server_id' => 'x']);
            // Blocked public resource and prompt Abilities that are still registered.
            test_register('acme/secret-resource', 'acme', ['mcp' => ['public' => true, 'type' => 'resource']]);
            test_register('acme/write-prompt', 'acme', ['mcp' => ['public' => true, 'type' => 'prompt']]);
            test_read_mode(true);
            add_filter('novamira_read_mode_abilities', static function (array $abilities): array {
                $abilities['acme/allowed-prompt'] = [
                    'execute' => 'test_pro_read_execute',
                    'permission' => 'novamira_permission_callback',
                ];
                return $abilities;
            });
            test_register('acme/allowed-prompt', 'acme', ['mcp' => ['public' => true, 'type' => 'prompt']]);
            return [
                'off' => $off,
                'off_config' => $off_config,
                'on' => novamira_mcp_server_components($trio, $discover),
                'on_config' => novamira_read_mode_default_server_config([
                    'tools' => $trio,
                    'resources' => ['acme/secret-resource'],
                    'prompts' => ['acme/write-prompt'],
                    'server_id' => 'x',
                ]),
            ];
        })(),
        'errors' => (static function (): array {
            $off = novamira_read_mode_unavailable_ability_error('novamira/execute-php');
            test_read_mode(true);
            return [
                'off' => $off,
                'blocked' => test_export(novamira_read_mode_unavailable_ability_error('novamira/execute-php')),
                'allowed' => novamira_read_mode_unavailable_ability_error('novamira/read-query'),
                'empty' => novamira_read_mode_unavailable_ability_error(''),
                'route' => test_export(novamira_read_mode_blocked_error('uploading files through an upload link')),
                'instructions' => novamira_read_mode_instruction_lines(),
            ];
        })(),
        'agent-surfaces-on', 'agent-surfaces-off' => (static function () use ($root, $scenario): array {
            test_define_environment();
            $GLOBALS['skill_source_loads'] = [];
            if ($scenario === 'agent-surfaces-on') {
                test_read_mode(true);
            }
            function novamira_server_compatibility(): array
            {
                return ['plugin_version' => 'test'];
            }
            // What the skill catalog, Pro's memory preview and license notice, and the administrator's Context add.
            add_filter(
                'novamira_discover_abilities_instructions',
                static fn(string $instructions): string => $instructions . "\n\nINJECTED: skill catalog, Pro memory preview.",
            );
            add_filter(
                'novamira_visual_backend_discovery_instructions',
                static fn(string $instructions): string => $instructions . "\n\nINJECTED: Visual extension guidance.",
            );

            require $root . '/vendor/novamira/mcp-adapter/autoload.php';
            require $root . '/includes/ability-metadata.php';
            require $root . '/includes/abilities/agent-context.php';
            require $root . '/includes/abilities/discover-abilities.php';
            require $root . '/includes/chat.php';
            require $root . '/novamira-visual/includes/BackendTools.php';
            require $root . '/novamira-visual/includes/BackendApprovals.php';
            require $root . '/includes/instructions-admin.php';
            require $root . '/includes/skills/parser.php';
            require $root . '/includes/skills/prompts.php';

            $context = novamira_build_agent_context();
            \Novamira\Skills\Prompts\register_dynamic_abilities();

            return [
                'agent_context_description' => $GLOBALS['abilities']['novamira/agent-context']->get_description(),
                'agent_context_instructions' => $context['instructions'],
                'agent_context_skills' => $context['skills'],
                'agent_context_read_mode' => $context['read_mode'],
                'discover_instructions' => novamira_execute_discover_abilities()['novamira_instructions'],
                'chat_instructions' => novamira_chat_server_instructions(),
                'visual_instructions' => \Novamira\Visual\BackendTools\discovery_instructions(),
                'admin_preview' => \Novamira\Context\context_system_instructions_preview(),
                'skill_prompts' => array_values(array_filter(
                    array_keys($GLOBALS['abilities']),
                    static fn(string $name): bool => str_starts_with($name, 'novamira/skill-prompt-'),
                )),
                'skill_source_loads' => $GLOBALS['skill_source_loads'],
                'read_mode_instructions' => novamira_read_mode_instructions(),
            ];
        })(),
        'instructions' => (static function (): array {
            test_define_environment();
            $off = novamira_build_server_instructions();
            test_read_mode(true);
            return ['off' => $off, 'on' => novamira_build_server_instructions()];
        })(),
        'hooks' => (static function (): array {
            novamira_register_read_mode_hooks();
            return array_map(
                static fn(array $hook): array => [$hook[0], is_string($hook[1]) ? $hook[1] : 'closure', $hook[2]],
                $GLOBALS['hooks'],
            );
        })(),
        'annotations' => (static function () use ($root): array {
            test_read_mode(true);
            require $root . '/vendor/novamira/mcp-adapter/autoload.php';
            novamira_register_read_mode_hooks();
            \Novamira\Vendor\WP\MCP\Abilities\GetAbilityInfoAbility::register();
            \Novamira\Vendor\WP\MCP\Abilities\ExecuteAbilityAbility::register();
            require $root . '/includes/abilities/read-file.php';
            require $root . '/includes/abilities/list-directory.php';
            require $root . '/includes/abilities/agent-context.php';
            require $root . '/includes/abilities/discover-abilities.php';
            require $root . '/includes/skills/abilities/skill-get.php';
            \Novamira\Skills\Abilities\SkillGet\register();

            $abilities = [];
            foreach (novamira_read_mode_allowed_abilities() as $name) {
                $ability = wp_get_ability($name);
                $args = $ability instanceof WP_Ability ? $ability->get_args() : null;
                $permission = $args['permission_callback'] ?? null;
                if ($permission instanceof \Novamira\ReadMode\PermissionGuard) {
                    $permission = $permission->wrapped_callback();
                }
                $abilities[$name] = $args === null ? null : [
                    'annotations' => $ability->get_meta()['annotations'] ?? null,
                    'permission_callback' => is_string($permission) ? $permission : 'callable',
                    'category' => $ability->get_category(),
                    // Registered from the real files, through the guard: the trusted map must match them.
                    'trusted' => novamira_read_mode_trusts_ability($ability),
                    'guarded' => ($args['permission_callback'] ?? null) instanceof \Novamira\ReadMode\PermissionGuard,
                ];
            }

            return ['abilities' => $abilities, 'categories' => array_keys($GLOBALS['ability_categories'])];
        })(),
        'skill-get-previous-on', 'skill-get-previous-off' => (static function () use ($root, $scenario): array {
            if ($scenario === 'skill-get-previous-on') {
                test_read_mode(true);
            }
            // A previous owner of skill-get whose callback was never audited for read mode.
            wp_register_ability('novamira/skill-get', [
                'category' => 'skill',
                'execute_callback' => static function (mixed $input): array {
                    update_option('previous_skill_get_side_effect', 'written');
                    return ['found' => true, 'slug' => (string) ($input['slug'] ?? ''), 'content' => 'from previous'];
                },
            ]);
            require $root . '/includes/skills/abilities/skill-get.php';
            \Novamira\Skills\Abilities\SkillGet\register();

            return [
                'result' => wp_get_ability('novamira/skill-get')?->execute(['slug' => 'unknown-skill']),
                'side_effect' => $GLOBALS['options']['previous_skill_get_side_effect'] ?? null,
            ];
        })(),
        'skill-get-read-mode' => (static function () use ($root): array {
            test_read_mode(true);
            $GLOBALS['test_skills'] = [
                'read-skill' => [
                    'slug' => 'read-skill',
                    'description' => 'A skill that only reads.',
                    'content' => 'Read skill body.',
                    'source' => 'test',
                    'read_mode' => true,
                ],
                'write-skill' => [
                    'slug' => 'write-skill',
                    'description' => 'A skill that changes the site.',
                    'content' => 'Write skill body.',
                    'source' => 'test',
                ],
            ];
            require $root . '/includes/skills/parser.php';
            require $root . '/includes/skills/abilities/skill-get.php';

            $read = \Novamira\Skills\Abilities\SkillGet\execute(['slug' => 'read-skill']);
            $write = \Novamira\Skills\Abilities\SkillGet\execute(['slug' => 'write-skill']);

            return [
                'read' => is_array($read) ? ['found' => $read['found'], 'content' => $read['content']] : null,
                'write' => $write instanceof WP_Error ? $write->get_error_code() : $write,
            ];
        })(),
        default => ['unknown_scenario' => $scenario],
    };

    echo json_encode($result, JSON_THROW_ON_ERROR);
}
