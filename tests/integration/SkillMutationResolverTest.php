<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SkillMutationResolverTest extends TestCase
{
    public function testMutationTargetCentralizesUserFeatureInfrastructureAndExternalPolicy(): void
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');
            define('Novamira\Skills\Cpt\POST_TYPE', 'novamira_skill');

            class WP_Error {
                public function __construct(
                    private string $code,
                    private string $message = '',
                    private mixed $data = null,
                ) {}
                public function get_error_code(): string { return $this->code; }
                public function get_error_message(): string { return $this->message; }
            }
            class WP_Post {
                public function __construct(
                    public int $ID,
                    public string $post_name,
                ) {}
            }

            function __(string $text, string $domain = 'default'): string { return $text; }
            function do_action(string $hook, mixed ...$args): void {}
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
                if ($hook !== 'novamira_skill_lookup_sources') { return $value; }
                return [
                    'fixture' => [
                        'id' => 'fixture',
                        'priority' => 10,
                        'label' => 'Fixture Plugin',
                        'loader' => static fn(): array => [
                            ['slug' => 'novamira-design'],
                            ['slug' => 'skill-creator'],
                            ['slug' => 'third-party-skill'],
                        ],
                    ],
                ];
            }
            function get_posts(array $args): array {
                return ($args['name'] ?? '') === 'user-skill' ? [new WP_Post(42, 'user-skill')] : [];
            }
            function get_option(string $name, mixed $default_value = false): mixed { return $default_value; }
            function get_site_option(string $name, mixed $default_value = false): mixed { return $default_value; }
            function get_current_blog_id(): int { return 1; }
            function is_multisite(): bool { return false; }
            function novamira_boot_design_feature(): void {}
            function novamira_boot_chat_feature(): void {}
            function novamira_boot_visual_feature(): void {}
            function novamira_boot_block_editor_queue_feature(): void {}
            function novamira_deactivate_block_editor_queue_feature(): void {}

            require $argv[1] . '/includes/features/api.php';
            \Novamira\Features\initialize_features();
            require $argv[1] . '/includes/skills/sources.php';
            require $argv[1] . '/includes/skills/abilities/skill-write.php';

            $user = \Novamira\Skills\Abilities\SkillWrite\resolve_mutation_target('user-skill');
            $managed = \Novamira\Skills\Abilities\SkillWrite\resolve_mutation_target('novamira-design');
            $infrastructure = \Novamira\Skills\Abilities\SkillWrite\resolve_mutation_target('skill-creator');
            $external = \Novamira\Skills\Abilities\SkillWrite\resolve_mutation_target('third-party-skill');
            $missing = \Novamira\Skills\Abilities\SkillWrite\resolve_mutation_target('missing');

            echo json_encode([
                'user_id' => $user instanceof WP_Post ? $user->ID : null,
                'managed' => $managed instanceof WP_Error ? $managed->get_error_code() : null,
                'infrastructure' => $infrastructure instanceof WP_Error ? $infrastructure->get_error_code() : null,
                'external' => [
                    'code' => $external instanceof WP_Error ? $external->get_error_code() : null,
                    'message' => $external instanceof WP_Error ? $external->get_error_message() : null,
                ],
                'missing' => $missing,
            ]);
            PHP;
        $command = sprintf(
            '%s -r %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($root),
        );
        $output = (string) shell_exec($command);
        $result = json_decode($output, associative: true);
        self::assertIsArray($result, $output);

        self::assertSame(42, $result['user_id']);
        self::assertSame('skill_managed_by_feature', $result['managed']);
        self::assertSame('system_skill', $result['infrastructure']);
        self::assertSame('skill_read_only', $result['external']['code']);
        self::assertStringContainsString('Fixture Plugin', $result['external']['message']);
        self::assertNull($result['missing']);
    }
}
