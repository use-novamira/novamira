<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for McpAdapterBuiltinAbilitiesRaceTest: php stock-mcp-component-runner.php <plugin root>.
 *
 * Components of an unprefixed MCP Adapter whose layout Novamira does not know: McpTool and McpResource
 * without the private `ability` field (renamed), and McpPrompt with it holding something other than an
 * Ability. Prints which of them Novamira treats as running a Novamira Ability, and every PHP warning raised.
 */

namespace WP\MCP\Domain\Tools {
    final class McpTool
    {
        /** @param array<string, mixed> $context */
        public function __construct(
            private array $context,
        ) {}

        public function get_observability_context(): array
        {
            return $this->context;
        }
    }
}

namespace WP\MCP\Domain\Resources {
    final class McpResource
    {
        /** @param array<string, mixed> $context */
        public function __construct(
            private array $context,
            private mixed $wrapped = null,
        ) {}

        public function get_observability_context(): array
        {
            return $this->context;
        }
    }
}

namespace WP\MCP\Domain\Prompts {
    final class McpPrompt
    {
        /** @param array<string, mixed> $context */
        public function __construct(
            private array $context,
            private mixed $ability = null,
        ) {}

        public function get_observability_context(): array
        {
            return $this->context;
        }
    }
}

namespace {
    use WP\MCP\Domain\Prompts\McpPrompt;
    use WP\MCP\Domain\Resources\McpResource;
    use WP\MCP\Domain\Tools\McpTool;

    if ($argc !== 2) {
        exit(2);
    }

    define('ABSPATH', '/');
    error_reporting(E_ALL);
    $warnings = [];
    set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });

    class WP_Ability {}

    function wp_has_ability(string $name): bool
    {
        return false;
    }

    require $argv[1] . '/includes/abilities/stock-mcp-adapter.php';

    $cases = [
        'tool reporting a Novamira Ability' => new McpTool(['source' => 'ability', 'ability_name' => 'novamira/execute-php']),
        "tool reporting another plugin's Ability" => new McpTool(['source' => 'ability', 'ability_name' => 'acme/ping']),
        'tool reporting an Ability without its name' => new McpTool(['source' => 'ability']),
        'tool built from a callable' => new McpTool(['source' => 'array']),
        'tool reporting no source' => new McpTool([]),
        'tool reporting an unknown source' => new McpTool(['source' => 'other']),
        'resource built from a callable, with a renamed field' => new McpResource(['source' => 'array'], wrapped: 'x'),
        'resource reporting no source, with a renamed field' => new McpResource([], wrapped: 'x'),
        'prompt built from a builder' => new McpPrompt(['source' => 'builder']),
        'prompt holding something other than an Ability' => new McpPrompt(['source' => 'builder'], ability: new stdClass()),
    ];
    $runs_novamira = [];
    foreach ($cases as $label => $component) {
        $runs_novamira[$label] = novamira_stock_mcp_component_runs_novamira_ability($component);
    }

    echo json_encode(['runs_novamira' => $runs_novamira, 'warnings' => $warnings], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
