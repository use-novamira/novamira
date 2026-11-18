<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The parts of the unprefixed MCP Adapter (wordpress/mcp-adapter 0.6.1, WP\MCP\Core\McpAdapter) that
 * another plugin bundles and that decide when its built-in Abilities exist: instance() hooks init() to
 * rest_api_init:15 (init:20 under WP-CLI); init() attaches the built-in category and Ability
 * registration only when the default server is enabled, then fires `mcp_adapter_init`, on which the
 * default server is built from the Abilities registry. Its built-in Abilities are the adapter's real ones.
 */

namespace WP\MCP\Core;

foreach (['Abilities/McpAbilityExposure', 'Abilities/McpAbilityHelperTrait', 'Domain/Utils/AbilityArgumentNormalizer', 'Abilities/DiscoverAbilitiesAbility', 'Abilities/GetAbilityInfoAbility', 'Abilities/ExecuteAbilityAbility'] as $novamira_stock_file) {
    require_once dirname(__DIR__, levels: 2) . '/vendor/wordpress/mcp-adapter/includes/' . $novamira_stock_file . '.php';
}

final class McpAdapter
{
    public const ABILITIES = [
        'mcp-adapter/discover-abilities',
        'mcp-adapter/get-ability-info',
        'mcp-adapter/execute-ability',
    ];

    private static self $instance;
    private static bool $initialized = false;

    /** @var array<string, bool>|null What the default server found, null until it is built. */
    public ?array $server_abilities = null;

    /** @var array<string, \WP_Ability> */
    public array $registered = [];

    /** @var array<string, mixed>|null The default server's configuration after its filter. */
    public ?array $server_config = null;

    public static function instance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
            if (defined('WP_CLI') && constant('WP_CLI')) {
                add_action('init', [self::$instance, 'init'], 20);
            } else {
                add_action('rest_api_init', [self::$instance, 'init'], 15);
            }
        }

        return self::$instance;
    }

    public function init(): void
    {
        if (self::$initialized) {
            return;
        }

        $GLOBALS['novamira_test_trace'][] = 'stock adapter: init, registry booted='
            . var_export(did_action('wp_abilities_api_init') > 0, return: true);
        if (apply_filters('mcp_adapter_create_default_server', true)) {
            add_action('wp_abilities_api_categories_init', [$this, 'register_default_category']);
            add_action('wp_abilities_api_init', [$this, 'register_default_abilities']);
            add_action('mcp_adapter_init', [$this, 'create_default_server']);
        }
        do_action('mcp_adapter_init', $this);
        self::$initialized = true;
    }

    /**
     * Stands in for DefaultServerFactory::create(): every MCP-public resource and prompt Ability, through
     * the configuration filter, then a lookup of each tool's Ability.
     */
    public function create_default_server(): void
    {
        $by_type = ['resource' => [], 'prompt' => []];
        foreach (wp_get_abilities() as $ability) {
            $type = $ability->get_meta()['mcp']['type'] ?? 'tool';
            if (isset($by_type[$type]) && \WP\MCP\Abilities\McpAbilityExposure::is_public($ability)) {
                $by_type[$type][] = $ability->get_name();
            }
        }
        $this->server_config = apply_filters('mcp_adapter_default_server_config', [
            'tools' => self::ABILITIES,
            'resources' => $by_type['resource'],
            'prompts' => $by_type['prompt'],
        ]);
        $this->server_abilities = [];
        foreach (self::ABILITIES as $ability_name) {
            $this->server_abilities[$ability_name] = wp_get_ability($ability_name) !== null;
        }
    }

    /** The stub builds no McpServer: its default server is only the lookup above. */
    public function get_servers(): array
    {
        return [];
    }

    public function register_default_category(): void
    {
        wp_register_ability_category('mcp-adapter', ['label' => 'MCP Adapter', 'description' => 'Stock copy.']);
    }

    public function register_default_abilities(): void
    {
        \WP\MCP\Abilities\DiscoverAbilitiesAbility::register();
        \WP\MCP\Abilities\GetAbilityInfoAbility::register();
        \WP\MCP\Abilities\ExecuteAbilityAbility::register();
        foreach (self::ABILITIES as $ability_name) {
            if (wp_has_ability($ability_name)) {
                $this->registered[$ability_name] = wp_get_ability($ability_name);
            }
        }
    }
}
