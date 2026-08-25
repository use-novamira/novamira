<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScopedMcpAdapterTest extends TestCase
{
    public function testLockedAdapterVersionIsScoped(): void
    {
        $installed = require __DIR__ . '/../../vendor/composer/installed.php';
        self::assertContains($installed['versions']['wordpress/mcp-adapter']['pretty_version'], ['0.6.1', 'v0.6.1']);

        require_once __DIR__ . '/../../vendor/novamira/mcp-adapter/autoload.php';

        self::assertTrue(class_exists(\Novamira\Vendor\WP\MCP\Core\McpAdapter::class));
        self::assertTrue(class_exists(\Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\Tool::class));
    }

    public function testGeneratedAdapterUsesPrivateRegistries(): void
    {
        $directory = __DIR__ . '/../../vendor/novamira/mcp-adapter';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        $contents = '';
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $contents .= file_get_contents($file->getPathname());
            }
        }

        self::assertStringNotContainsString("'mcp_adapter_", $contents);
        self::assertStringNotContainsString("'mcp-adapter/", $contents);
        self::assertStringContainsString("'novamira_mcp_adapter_init'", $contents);
        self::assertStringContainsString("'novamira-mcp-adapter/execute-ability'", $contents);
        self::assertStringContainsString("'novamira_mcp_adapter_sessions'", $contents);
    }

    public function testPublicAndPrivateAdapterClassesCanCoexist(): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';
        require_once __DIR__ . '/../../vendor/novamira/mcp-adapter/autoload.php';

        self::assertTrue(class_exists(\WP\MCP\Core\McpAdapter::class));
        self::assertTrue(class_exists(\Novamira\Vendor\WP\MCP\Core\McpAdapter::class));
        self::assertNotSame(\WP\MCP\Core\McpAdapter::class, \Novamira\Vendor\WP\MCP\Core\McpAdapter::class);
    }
}
