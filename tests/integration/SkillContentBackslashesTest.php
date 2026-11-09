<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Skill bodies keep their backslashes: PHP namespaces and regexes in an uploaded SKILL.md or an
 * edited skill must be stored as written, while a body that a client encoded twice is still decoded.
 */
final class SkillContentBackslashesTest extends TestCase
{
    private const BODY = "```php\n\$plugin = \\Elementor\\Plugin::\$instance;\nuse Acme\\vendor\\name\\Thing;\npreg_match('/^\\d{5}\$/', \$zip);\n```";

    public function testUnescapeKeepsBackslashesInABodyWithRealLineBreaks(): void
    {
        $result = $this->run_child(<<<'PHP'
            define('ABSPATH', '/');
            require $argv[1] . '/includes/skills/parser.php';
            echo json_encode([
                'body' => \Novamira\Skills\Parser\unescape_content($argv[2]),
                'one_line_namespace' => \Novamira\Skills\Parser\unescape_content('use Acme\Shop\Cart;'),
                'double_encoded' => \Novamira\Skills\Parser\unescape_content('Step one\nStep two\tdone'),
            ]);
            PHP);

        self::assertSame(self::BODY, $result['body']);
        self::assertSame('use Acme\Shop\Cart;', $result['one_line_namespace']);
        self::assertSame("Step one\nStep two\tdone", $result['double_encoded']);
    }

    public function testSkillEditStoresContentAndDescriptionWithBackslashes(): void
    {
        $result = $this->run_child(<<<'PHP'
            define('ABSPATH', '/');
            define('Novamira\Skills\Cpt\POST_TYPE', 'novamira_skill');
            define('Novamira\Skills\Cpt\META_ENABLE_PROMPT', '_enable_prompt');
            define('Novamira\Skills\Cpt\META_ENABLE_AGENTIC', '_enable_agentic');
            define('Novamira\Skills\Cpt\META_READ_MODE', '_read_mode');

            class WP_Error {
                public function __construct(
                    private string $code,
                    private string $message = '',
                    private mixed $data = null,
                ) {}
                public function get_error_code(): string { return $this->code; }
            }
            class WP_Post {
                public string $post_excerpt = 'Old description';
                public string $post_content = 'Old body';
                public string $post_status = 'publish';
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
                        'loader' => static fn(): array => [],
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
            function wp_slash(mixed $value): mixed {
                return is_string($value) ? addslashes($value) : $value;
            }
            // Like core, wp_update_post() unslashes the post data it receives.
            function wp_update_post(array $postarr, bool $wp_error = false): int {
                foreach ($postarr as $field => $value) {
                    $GLOBALS['saved'][$field] = is_string($value) ? stripslashes($value) : $value;
                }
                return (int) $postarr['ID'];
            }

            require $argv[1] . '/includes/features/api.php';
            \Novamira\Features\initialize_features();
            require $argv[1] . '/includes/skills/parser.php';
            require $argv[1] . '/includes/skills/sources.php';
            require $argv[1] . '/includes/skills/abilities/skill-write.php';
            require $argv[1] . '/includes/skills/abilities/skill-edit.php';

            $GLOBALS['saved'] = [];
            $edit = \Novamira\Skills\Abilities\SkillEdit\execute([
                'slug' => 'user-skill',
                'description' => 'Matches \d{5} ZIP codes',
                'content' => $argv[2],
            ]);

            echo json_encode([
                'changed' => is_array($edit) ? $edit['changed_fields'] : $edit->get_error_code(),
                'post_excerpt' => $GLOBALS['saved']['post_excerpt'] ?? null,
                'post_content' => $GLOBALS['saved']['post_content'] ?? null,
            ]);
            PHP);

        self::assertSame(['description', 'content'], $result['changed']);
        self::assertSame('Matches \d{5} ZIP codes', $result['post_excerpt']);
        self::assertSame(self::BODY, $result['post_content']);
    }

    /** @return array<string, mixed> */
    private function run_child(string $script): array
    {
        $command = sprintf(
            '%s -r %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg(dirname(__DIR__, levels: 2)),
            escapeshellarg(self::BODY),
        );
        $output = (string) shell_exec($command);
        $result = json_decode($output, associative: true);
        self::assertIsArray($result, $output);

        return $result;
    }
}
