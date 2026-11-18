<?php
/**
 * Signature-only stubs for the unprefixed WordPress MCP Adapter (WP\MCP\Core\McpAdapter, wordpress/mcp-adapter
 * 0.6.1, GPL-2.0-or-later) that other plugins may bundle, for static analysis only. Novamira itself only
 * ships the scoped copy (Novamira\Vendor\WP\MCP). Not shipped in the release.
 */

namespace WP\MCP\Core {
    final class McpAdapter
    {
        public static function instance(): self {}

        public function init(): void {}

        public function register_default_category(): void {}

        public function register_default_abilities(): void {}

        /** @return array<string, McpServer> */
        public function get_servers(): array {}
    }
}

namespace WP\MCP\Core {
    class McpServer
    {
        public function get_mcp_tool(string $tool_name): ?\WP\MCP\Domain\Tools\McpTool {}

        public function get_mcp_resource(string $resource_uri): ?\WP\MCP\Domain\Resources\McpResource {}

        public function get_mcp_prompt(string $prompt_name): ?\WP\MCP\Domain\Prompts\McpPrompt {}
    }
}

namespace WP\MCP\Domain\Tools {
    final class McpTool
    {
        /** @return array<string, mixed> */
        public function get_observability_context(): array {}
    }
}

namespace WP\MCP\Domain\Resources {
    final class McpResource
    {
        /** @return array<string, mixed> */
        public function get_observability_context(): array {}
    }
}

namespace WP\MCP\Domain\Prompts {
    final class McpPrompt
    {
        /** @return array<string, mixed> */
        public function get_observability_context(): array {}
    }
}

namespace WP\McpSchema\Server\Tools\DTO {
    class Tool
    {
        public function getName(): string {}
    }
}

namespace WP\McpSchema\Server\Resources\DTO {
    class Resource
    {
        public function getUri(): string {}
    }
}

namespace WP\McpSchema\Server\Prompts\DTO {
    class Prompt
    {
        public function getName(): string {}
    }
}

namespace WP\MCP\Core {
    class McpComponentRegistry {}
}
