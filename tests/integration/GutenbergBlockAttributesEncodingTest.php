<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Empty block attributes remain JSON objects from normalization through the stored-spec read path and
 * the payload returned to the Block Editor Queue.
 */
final class GutenbergBlockAttributesEncodingTest extends TestCase
{
    /** @var array{normalized: string, payload: string}|null */
    private static ?array $result = null;

    public function testBlocksWithoutAttributesEncodeAsObjectsInTheFinalizerPayload(): void
    {
        $result = $this->scenario();
        $payload = json_decode($result['payload']);

        self::assertIsObject($payload);
        self::assertIsArray($payload->blocks);
        self::assertIsObject($payload->blocks[0]->attributes);
        self::assertSame('{}', json_encode($payload->blocks[0]->attributes, JSON_THROW_ON_ERROR));
        self::assertNotSame('[]', json_encode($payload->blocks[0]->attributes, JSON_THROW_ON_ERROR));

        self::assertIsObject($payload->blocks[0]->innerBlocks[0]->attributes);
        self::assertSame('{}', json_encode($payload->blocks[0]->innerBlocks[0]->attributes, JSON_THROW_ON_ERROR));
    }

    public function testNormalizedSpecStoredByTheQueueEncodesEmptyAttributesAsObjects(): void
    {
        $normalized = json_decode($this->scenario()['normalized']);

        self::assertIsArray($normalized);
        self::assertIsObject($normalized[0]->attributes);
        self::assertSame('{}', json_encode($normalized[0]->attributes, JSON_THROW_ON_ERROR));
        self::assertNotSame('[]', json_encode($normalized[0]->attributes, JSON_THROW_ON_ERROR));
    }

    /** @return array{normalized: string, payload: string} */
    private function scenario(): array
    {
        if (self::$result !== null) {
            return self::$result;
        }

        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');

            class WP_Error {}
            class WP_Post {
                public int $ID;

                public function __construct(int $id) {
                    $this->ID = $id;
                }
            }

            function add_action(...$args): void {}
            function add_filter(...$args): void {}
            function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
            function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
                unset($post_id, $single);
                return $GLOBALS['novamira_test_meta'][$key] ?? '';
            }
            function wp_json_encode(mixed $value): string|false { return json_encode($value); }

            require $argv[1] . '/includes/abilities/gutenberg/bootstrap.php';

            $normalized = \Novamira\Abilities\Gutenberg\normalize_blocks([
                [
                    'name' => 'core/list',
                    'innerBlocks' => [
                        ['name' => 'core/list-item', 'attributes' => [], 'innerBlocks' => []],
                    ],
                ],
            ]);

            $GLOBALS['novamira_test_meta'][\Novamira\Abilities\Gutenberg\META_BLOCK_SPEC] = json_encode([
                [
                    'name' => 'core/list',
                    'attributes' => [],
                    'innerBlocks' => [
                        ['name' => 'core/list-item', 'attributes' => [], 'innerBlocks' => []],
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
            $blocks = \Novamira\Abilities\Gutenberg\item_blocks(new WP_Post(101));

            echo json_encode([
                'normalized' => wp_json_encode($normalized),
                'payload' => wp_json_encode(['blocks' => $blocks]),
            ], JSON_THROW_ON_ERROR);
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
        self::assertIsString($result['normalized']);
        self::assertIsString($result['payload']);

        /** @var array{normalized: string, payload: string} $result */
        self::$result = $result;

        return $result;
    }
}
