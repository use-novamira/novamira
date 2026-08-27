<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExecuteAbilityOpenParametersTest extends TestCase
{
    public function testExecuteAbilityExposesOpenParametersAndStillForwardsArguments(): void
    {
        $result = $this->runIntegrationScript();

        self::assertSame('mcp-adapter-execute-ability', $result['tool_name']);
        self::assertTrue($result['parameters_properties_is_object']);
        self::assertStringContainsString('"properties":{}', $result['parameters_json']);
        self::assertStringContainsString('"additionalProperties":true', $result['parameters_json']);
        self::assertTrue($result['empty_additional_properties_is_object']);
        self::assertStringContainsString('"additionalProperties":{}', $result['object_keywords_json']);
        self::assertSame('Parameters to pass to the ability', $result['parameters_description']);
        self::assertSame(1, $result['execute_ability_count']);
        self::assertTrue($result['ability_name_unchanged']);
        self::assertTrue($result['required_unchanged']);
        self::assertTrue($result['permission_callback_unchanged']);
        self::assertTrue($result['execute_callback_unchanged']);
        self::assertTrue($result['meta_unchanged']);
        self::assertSame(
            ['success' => true, 'data' => ['received' => ['message' => 'hello']]],
            $result['execution'],
        );
    }

    /** @return array<string, mixed> */
    private function runIntegrationScript(): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');
            $GLOBALS['novamira_test_abilities'] = [];
            $GLOBALS['novamira_test_ability_args'] = [];

            class WP_Error {
                public function __construct(
                    private string $code = '',
                    private string $message = '',
                    private mixed $data = null,
                ) {}
                public function get_error_message(): string { return $this->message; }
            }

            class WP_Ability {
                public function __construct(private string $name, private array $args) {}
                public function get_name(): string { return $this->name; }
                public function get_label(): string { return $this->args['label']; }
                public function get_description(): string { return $this->args['description']; }
                public function get_category(): string { return $this->args['category']; }
                public function get_input_schema(): array { return $this->args['input_schema'] ?? []; }
                public function get_output_schema(): array { return $this->args['output_schema'] ?? []; }
                public function get_meta(): array { return $this->args['meta'] ?? []; }
                public function execute(mixed $input = null): mixed {
                    return ($this->args['execute_callback'])($input);
                }
                public function check_permissions(mixed $input = null): mixed {
                    return ($this->args['permission_callback'])($input);
                }
            }

            function __(string $text, string $domain = 'default'): string { return $text; }
            function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
            function wp_register_ability(string $name, array $args): WP_Ability {
                if (isset($GLOBALS['novamira_test_abilities'][$name])) {
                    throw new RuntimeException("Ability already registered: {$name}");
                }
                $ability = new WP_Ability($name, $args);
                $GLOBALS['novamira_test_abilities'][$name] = $ability;
                $GLOBALS['novamira_test_ability_args'][$name] = $args;
                return $ability;
            }
            function wp_unregister_ability(string $name): void {
                unset($GLOBALS['novamira_test_abilities'][$name], $GLOBALS['novamira_test_ability_args'][$name]);
            }
            function wp_has_ability(string $name): bool {
                return isset($GLOBALS['novamira_test_abilities'][$name]);
            }
            function wp_get_ability(string $name): ?WP_Ability {
                return $GLOBALS['novamira_test_abilities'][$name] ?? null;
            }
            function wp_get_abilities(): array { return $GLOBALS['novamira_test_abilities']; }
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
                if ($hook === 'novamira_mcp_adapter_tool_name'
                    && ($args[0] ?? null) instanceof WP_Ability
                    && $args[0]->get_name() === 'novamira-mcp-adapter/execute-ability'
                ) {
                    return 'mcp-adapter-execute-ability';
                }
                return $value;
            }

            require $argv[1] . '/vendor/novamira/mcp-adapter/autoload.php';

            $execute_class = \Novamira\Vendor\WP\MCP\Abilities\ExecuteAbilityAbility::class;
            $execute_class::register();
            $ability_name = 'novamira-mcp-adapter/execute-ability';
            $upstream_args = $GLOBALS['novamira_test_ability_args'][$ability_name];

            require $argv[1] . '/includes/abilities/discover-abilities.php';

            $patched_args = $GLOBALS['novamira_test_ability_args'][$ability_name];
            $patched_ability = wp_get_ability($ability_name);
            $execute_ability_count = count(array_filter(
                array_keys($GLOBALS['novamira_test_abilities']),
                static fn(string $name): bool => $name === $ability_name,
            ));
            $build = \Novamira\Vendor\WP\MCP\Domain\Tools\RegisterAbilityAsMcpTool::build($patched_ability);
            if (is_wp_error($build)) {
                throw new RuntimeException($build->get_error_message());
            }
            $tool = $build['tool']->toArray();
            $wire_schema = novamira_normalize_empty_schema_properties($tool['inputSchema']);
            $parameters_json = json_encode(
                $wire_schema['properties']['parameters'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
            $parameters_object = json_decode($parameters_json, flags: JSON_THROW_ON_ERROR);
            $object_keywords_json = json_encode(
                novamira_normalize_empty_schema_properties(['additionalProperties' => []]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
            $object_keywords = json_decode($object_keywords_json, flags: JSON_THROW_ON_ERROR);

            wp_register_ability('vendor/echo-arguments', [
                'label' => 'Echo arguments',
                'description' => 'Return the supplied arguments.',
                'category' => 'vendor',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['message' => ['type' => 'string']],
                    'required' => ['message'],
                ],
                'output_schema' => ['type' => 'object'],
                'permission_callback' => static fn(array $input): bool => true,
                'execute_callback' => static fn(array $input): array => ['received' => $input],
                'meta' => ['mcp' => ['public' => true, 'type' => 'tool']],
            ]);
            $execution = $patched_ability->execute([
                'ability_name' => 'vendor/echo-arguments',
                'parameters' => ['message' => 'hello'],
            ]);

            echo json_encode([
                'tool_name' => $tool['name'],
                'parameters_properties_is_object' => $parameters_object->properties instanceof stdClass,
                'parameters_json' => $parameters_json,
                'empty_additional_properties_is_object' => $object_keywords->additionalProperties instanceof stdClass,
                'object_keywords_json' => $object_keywords_json,
                'parameters_description' => $wire_schema['properties']['parameters']->description,
                'execute_ability_count' => $execute_ability_count,
                'ability_name_unchanged' => $patched_args['input_schema']['properties']['ability_name']
                    === $upstream_args['input_schema']['properties']['ability_name'],
                'required_unchanged' => $patched_args['input_schema']['required']
                    === $upstream_args['input_schema']['required'],
                'permission_callback_unchanged' => $patched_args['permission_callback']
                    === $upstream_args['permission_callback'],
                'execute_callback_unchanged' => $patched_args['execute_callback']
                    === $upstream_args['execute_callback'],
                'meta_unchanged' => $patched_args['meta'] === $upstream_args['meta'],
                'execution' => $execution,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            PHP;

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
