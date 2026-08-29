<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Builder-owned block namespaces (divi/* by default, plus any namespace added through the filter) are
 * rejected before a block spec reaches the
 * Block Editor Queue or the direct write path, and finalizer registration failures for those
 * namespaces carry a pointer to the builder's own abilities. The Gutenberg ability files are loaded in
 * an isolated PHP process; post storage is an in-memory stub so the fail_item() → mark_batch_failed()
 * path runs end to end without WordPress.
 */
final class GutenbergBuilderOwnedBlocksTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $result = null;

    public function testTopLevelBuilderBlockIsRejectedWithItsPath(): void
    {
        $error = $this->scenarios()['top_level_divi'];

        self::assertSame('gutenberg_builder_owned_block', $error['code']);
        self::assertSame(422, $error['data']['status']);
        self::assertSame('block_spec[0]', $error['data']['path']);
        self::assertSame('divi/section', $error['data']['block_name']);
        self::assertSame('divi', $error['data']['namespace']);
        self::assertSame('Divi', $error['data']['builder']);
        self::assertStringContainsString('Block "divi/section" at block_spec[0] is a Divi builder block.', $error['message']);
        self::assertStringContainsString('dedicated Divi abilities', $error['message']);
        self::assertStringContainsString('tell the user instead of falling back to raw post content', $error['message']);
    }

    public function testNestedBuilderBlockIsRejectedWithTheNestedPath(): void
    {
        $error = $this->scenarios()['nested_divi'];

        self::assertSame('gutenberg_builder_owned_block', $error['code']);
        self::assertSame('block_spec[0].innerBlocks[1]', $error['data']['path']);
        self::assertSame('divi/text', $error['data']['block_name']);
        self::assertStringContainsString('at block_spec[0].innerBlocks[1]', $error['message']);
    }

    public function testEditorRegisteredBlocksPassTheValidator(): void
    {
        self::assertNull($this->scenarios()['core_and_third_party']);
    }

    public function testOnlyDiviIsBuilderOwnedByDefault(): void
    {
        $result = $this->scenarios();

        self::assertSame(['divi' => 'Divi'], $result['default_namespaces']);
        // Any other builder namespace is an ordinary editor-registered block until a filter lists it.
        self::assertNull($result['unlisted_namespace']);
    }

    public function testFilterCanRemoveAndAddNamespaces(): void
    {
        $result = $this->scenarios();

        self::assertNull($result['filter_removed_divi']);
        self::assertSame('gutenberg_builder_owned_block', $result['filter_added_custom']['code']);
        self::assertSame('Acme Builder', $result['filter_added_custom']['data']['builder']);
        self::assertSame('acme', $result['filter_added_custom']['data']['namespace']);
        self::assertStringContainsString('is an Acme Builder builder block', $result['filter_added_custom']['message']);
    }

    public function testAddPendingChangeRejectsBuilderBlocksBeforePersisting(): void
    {
        $result = $this->scenarios();

        self::assertSame('gutenberg_builder_owned_block', $result['add_pending_divi']['code']);
        self::assertSame(422, $result['add_pending_divi']['data']['status']);
        self::assertSame('block_spec[0].innerBlocks[0]', $result['add_pending_nested_divi']['data']['path']);
        // Editor-registered blocks get past every validator and reach the persistence layer.
        self::assertSame('test_persist_sentinel', $result['add_pending_third_party']['code']);
    }

    public function testWriteContentReportsTheBuilderCodeInsteadOfFinalizationRequired(): void
    {
        $result = $this->scenarios();

        self::assertSame('gutenberg_builder_owned_block', $result['write_content_divi']['code']);
        self::assertSame(422, $result['write_content_divi']['data']['status']);
        self::assertArrayNotHasKey('finalization_required', $result['write_content_divi']['data']);
        // Editor-registered static blocks still get the finalization pointer.
        self::assertSame('gutenberg_static_blocks_require_finalization', $result['write_content_core']['code']);
    }

    public function testAbilityTextsNameTheBuilderNamespaces(): void
    {
        $result = $this->scenarios();

        foreach (['add_pending_description', 'write_content_description'] as $key) {
            self::assertStringContainsString('divi/*', $result[$key]);
            self::assertStringContainsString('dedicated abilities', $result[$key]);
        }
    }

    public function testFinalizerRegistrationFailureCarriesTheBuilderHint(): void
    {
        $result = $this->scenarios();
        $rows = $result['compact_rows'];

        self::assertCount(3, $rows);
        self::assertSame('divi/section', $rows[0]['block_name']);
        self::assertStringStartsWith('Block "divi/section" was not registered in the block editor runtime.', $rows[0]['message']);
        self::assertStringContainsString('divi/* blocks are registered only inside the Divi builder runtime', $rows[0]['message']);
        self::assertStringContainsString('use the dedicated Divi abilities.', $rows[0]['message']);
        self::assertSame('Block "uagb/container" was not registered in the block editor runtime.', $rows[1]['message']);
        // The hint is tied to the registration failure code, not to the block name alone.
        self::assertSame('Block "divi/text" failed validation.', $rows[2]['message']);

        self::assertSame(
            ['divi/* blocks are registered only inside the Divi builder runtime and cannot be finalized by the Block Editor Queue; use the dedicated Divi abilities.'],
            $result['batch_hints'],
        );
        self::assertSame([], $result['batch_hints_without_builder']);
    }

    public function testRecompactingRowsDoesNotRepeatTheHint(): void
    {
        $result = $this->scenarios()['recompacted_rows'];

        self::assertTrue($result['equal'], 'compacting an already compacted row must be a no-op');
        self::assertSame(1, substr_count($result['first_message'], 'divi/* blocks are registered only inside'));
        self::assertLessThanOrEqual(300, $result['max_length']);
    }

    public function testOversizedFilterLabelIsCappedAndMessagesStayBounded(): void
    {
        $result = $this->scenarios()['oversized_label'];

        self::assertLessThanOrEqual(40, mb_strlen($result['label']));
        self::assertStringNotContainsString('<', $result['label']);
        self::assertStringStartsWith('Very Long Divi Label', $result['label']);
        self::assertStringContainsString('is a ' . $result['label'] . ' builder block', $result['validator_message']);
        self::assertLessThanOrEqual(300, mb_strlen($result['row_message']));
        self::assertStringEndsWith('use the dedicated ' . $result['label'] . ' abilities.', $result['row_message']);
        self::assertLessThanOrEqual(1000, mb_strlen($result['batch_message']));
        self::assertStringEndsWith('use the dedicated ' . $result['label'] . ' abilities.', $result['batch_message']);
        self::assertTrue($result['batch_idempotent']);
        self::assertSame(['divi' => 'Divi'], $result['malformed_namespaces_dropped']);
    }

    public function testManyBuildersKeepTheBatchMessageBoundedAndStable(): void
    {
        $result = $this->scenarios()['many_builders'];

        self::assertLessThanOrEqual(1000, $result['length']);
        self::assertTrue($result['idempotent']);
        self::assertStringStartsWith('One or more Gutenberg blocks were not registered', $result['message']);
        self::assertSame(6, $result['hint_count']);
        // Hints that do not fit are dropped whole: the message still ends with a complete hint.
        self::assertStringEndsWith(' abilities.', $result['message']);
        self::assertGreaterThanOrEqual(1, $result['included_count']);
        self::assertLessThan(6, $result['included_count']);
    }

    public function testShortBaseWithManyHintsRecomposesByteIdentically(): void
    {
        $result = $this->scenarios()['short_base_many_hints'];

        self::assertLessThanOrEqual(1000, mb_strlen($result['once']));
        self::assertSame($result['once'], $result['twice']);
        self::assertStringStartsWith('Failed. ', $result['once']);
        self::assertStringEndsWith(' abilities.', $result['once']);
        self::assertLessThan(8, $result['included_count']);
    }

    public function testHintPhraseInsideTheOriginalMessageIsPreserved(): void
    {
        $result = $this->scenarios()['phrase_in_message'];

        self::assertStringStartsWith($result['batch_base'], $result['batch_once']);
        self::assertSame($result['batch_once'], $result['batch_twice']);
        self::assertSame(1, substr_count($result['batch_once'], 'dedicated Divi abilities.'));
        self::assertStringStartsWith($result['row_base'], $result['row_once']);
        self::assertSame($result['row_once'], $result['row_twice']);
        self::assertLessThanOrEqual(300, mb_strlen($result['row_once']));
    }

    public function testDegenerateBaseMessagesComposeIdempotently(): void
    {
        $result = $this->scenarios()['degenerate_bases'];
        $divi = 'divi/* blocks are registered only inside the Divi builder runtime and cannot be finalized by the Block Editor Queue; use the dedicated Divi abilities.';
        $acme = 'acme/* blocks are registered only inside the Acme builder runtime and cannot be finalized by the Block Editor Queue; use the dedicated Acme abilities.';

        self::assertSame(['empty', 'whitespace', 'one_hint', 'two_hints'], array_keys($result['batch']));
        foreach ($result['batch'] as $case => $composition) {
            self::assertSame($composition['once'], $composition['twice'], $case);
            self::assertSame($divi . ' ' . $acme, $composition['once'], $case);
            self::assertLessThanOrEqual(1000, mb_strlen($composition['once']), $case);
        }
        foreach ($result['row'] as $case => $composition) {
            self::assertSame($composition['once'], $composition['twice'], $case);
            self::assertSame($divi, $composition['once'], $case);
            self::assertSame(1, substr_count($composition['once'], 'dedicated Divi abilities.'), $case);
        }
    }

    public function testMessagesWithoutHintsKeepTheFullBound(): void
    {
        $result = $this->scenarios()['no_hints_at_bound'];

        self::assertSame(300, mb_strlen($result['row_base']));
        self::assertSame($result['row_base'], $result['row_validation']);
        self::assertSame($result['row_base'], $result['row_registration']);
        self::assertSame(1000, mb_strlen($result['batch_base']));
        self::assertSame($result['batch_base'], $result['batch']);
    }

    public function testLabelsWithoutLettersOrDigitsFallBackToTheNamespace(): void
    {
        self::assertSame(
            ['dashes' => 'dashes', 'symbols' => 'symbols', 'boolean' => 'boolean', 'number' => 'number', 'mixed' => 'Acme 2'],
            $this->scenarios()['unusable_labels'],
        );
    }

    public function testBuilderRowBeyondTheCompactedFiveStillYieldsTheBatchHint(): void
    {
        $result = $this->scenarios()['late_builder_row'];

        self::assertCount(5, $result['compact_rows']);
        foreach ($result['compact_rows'] as $row) {
            self::assertStringNotContainsString('builder runtime', $row['message']);
        }
        self::assertSame(
            ['divi/* blocks are registered only inside the Divi builder runtime and cannot be finalized by the Block Editor Queue; use the dedicated Divi abilities.'],
            $result['hints'],
        );
    }

    public function testFailItemStoresTheHintedLastErrorOnTheBatch(): void
    {
        $result = $this->scenarios()['fail_item'];

        self::assertTrue($result['done']);
        self::assertSame('failed', $result['batch_status']);
        self::assertSame('failed', $result['item_status']);
        self::assertSame(
            'One or more Gutenberg blocks were not registered in the block editor runtime; canonical content was not written. divi/* blocks are registered only inside the Divi builder runtime and cannot be finalized by the Block Editor Queue; use the dedicated Divi abilities.',
            $result['last_error'],
        );
        self::assertSame($result['last_error'], $result['stored_last_error']);
        // The builder row was the seventh raw row: absent from the five stored rows, present in last_error.
        self::assertCount(5, $result['validation_errors']);
        self::assertSame('uagb/container', $result['validation_errors'][0]['block_name']);
        self::assertStringNotContainsString('builder runtime', $result['validation_errors'][0]['message']);
    }

    /** @return array<string, mixed> */
    private function scenarios(): array
    {
        if (self::$result === null) {
            self::$result = $this->runIntegrationScript();
        }

        return self::$result;
    }

    /** @return array<string, mixed> */
    private function runIntegrationScript(): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');
            $GLOBALS['novamira_test_abilities'] = [];
            $GLOBALS['novamira_test_filters'] = [];

            class WP_Error {
                public function __construct(
                    private string $code = '',
                    private string $message = '',
                    private mixed $data = null,
                ) {}
                public function get_error_code(): string { return $this->code; }
                public function get_error_message(): string { return $this->message; }
                public function get_error_data(): mixed { return $this->data; }
            }
            class WP_Post {
                public function __construct(
                    public int $ID = 1,
                    public string $post_type = 'page',
                    public string $post_title = 'Target',
                    public string $post_content = '',
                    public int $post_parent = 0,
                    public string $post_date_gmt = '2026-01-01 00:00:00',
                    public string $post_excerpt = '',
                ) {}
            }
            $GLOBALS['novamira_test_posts'] = [1 => new WP_Post()];
            $GLOBALS['novamira_test_meta'] = [];

            function __(string $text, string $domain = 'default'): string { return $text; }
            function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
            function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void {}
            function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void {}
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
                $filter = $GLOBALS['novamira_test_filters'][$hook] ?? null;
                return $filter === null ? $value : $filter($value);
            }
            function wp_register_ability(string $name, array $args): void {
                $GLOBALS['novamira_test_abilities'][$name] = $args;
            }
            function get_post(mixed $post_id): ?WP_Post { return $GLOBALS['novamira_test_posts'][(int) $post_id] ?? null; }
            function get_posts(array $args): array {
                $parent = (int) ($args['post_parent'] ?? 0);
                if ($parent === 0) {
                    return [];
                }
                return array_values(array_filter(
                    $GLOBALS['novamira_test_posts'],
                    static fn(WP_Post $post): bool => $post->post_parent === $parent,
                ));
            }
            function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
                return $GLOBALS['novamira_test_meta'][$post_id][$key] ?? '';
            }
            function update_post_meta(int $post_id, string $key, mixed $value): bool {
                $GLOBALS['novamira_test_meta'][$post_id][$key] = $value;
                return true;
            }
            function delete_post_meta(int $post_id, string $key): bool {
                unset($GLOBALS['novamira_test_meta'][$post_id][$key]);
                return true;
            }
            function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
            function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
            function sanitize_text_field(string $value): string { return trim($value); }
            function wp_strip_all_tags(string $value): string { return strip_tags($value); }
            function wp_insert_post(array $data, bool $wp_error = false): WP_Error {
                return new WP_Error('test_persist_sentinel', 'Persistence is out of scope for this test.');
            }

            require $argv[1] . '/includes/abilities/gutenberg/bootstrap.php';
            require $argv[1] . '/includes/abilities/gutenberg/add-pending-change.php';
            require $argv[1] . '/includes/abilities/gutenberg/write-content.php';
            // finalizer_runtime_status() lives in runtime.php, which is not loaded here.
            eval('namespace Novamira\\Abilities\\Gutenberg; function finalizer_runtime_status(?\\WP_Post $batch = null): array { return ["online" => false, "can_finalize_batch" => false]; }');

            $shape = static function (mixed $value): mixed {
                if ($value instanceof WP_Error) {
                    return [
                        'code' => $value->get_error_code(),
                        'message' => $value->get_error_message(),
                        'data' => $value->get_error_data(),
                    ];
                }
                return $value;
            };
            $block = static fn(string $name, array $inner = []): array => [
                'name' => $name,
                'attributes' => [],
                'innerBlocks' => $inner,
            ];
            $validate = static fn(array $blocks): mixed => $shape(
                \Novamira\Abilities\Gutenberg\validate_builder_owned_blocks(
                    \Novamira\Abilities\Gutenberg\normalize_blocks($blocks),
                ),
            );

            $out = [];
            $out['default_namespaces'] = \Novamira\Abilities\Gutenberg\builder_block_namespaces();
            $out['top_level_divi'] = $validate([$block('divi/section')]);
            $out['nested_divi'] = $validate([$block('core/group', [$block('core/paragraph'), $block('divi/text')])]);
            $out['core_and_third_party'] = $validate([
                $block('core/paragraph'),
                $block('uagb/container', [$block('kadence/rowlayout'), $block('generateblocks/container')]),
            ]);
            $out['unlisted_namespace'] = $validate([$block('acme/section')]);

            $GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces'] = static function (array $namespaces): array {
                unset($namespaces['divi']);
                $namespaces['acme'] = 'Acme Builder';
                return $namespaces;
            };
            $out['filter_removed_divi'] = $validate([$block('divi/section')]);
            $out['filter_added_custom'] = $validate([$block('core/group', [$block('acme/hero')])]);
            unset($GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces']);

            $out['add_pending_divi'] = $shape(\Novamira\Abilities\Gutenberg\gutenberg_add_pending_change([
                'target_id' => 1,
                'block_spec' => [$block('divi/section')],
            ]));
            $out['add_pending_nested_divi'] = $shape(\Novamira\Abilities\Gutenberg\gutenberg_add_pending_change([
                'target_id' => 1,
                'block_spec' => [$block('core/group', [$block('divi/text')])],
            ]));
            $out['add_pending_third_party'] = $shape(\Novamira\Abilities\Gutenberg\gutenberg_add_pending_change([
                'target_id' => 1,
                'block_spec' => [$block('core/paragraph'), $block('uagb/container')],
            ]));
            $out['write_content_divi'] = $shape(\Novamira\Abilities\Gutenberg\gutenberg_write_content([
                'target_id' => 1,
                'block_spec' => [$block('divi/section')],
            ]));
            $out['write_content_core'] = $shape(\Novamira\Abilities\Gutenberg\gutenberg_write_content([
                'target_id' => 1,
                'block_spec' => [$block('core/paragraph')],
            ]));

            $out['add_pending_description'] = $GLOBALS['novamira_test_abilities']['novamira/gutenberg-add-pending-change']['description'];
            $out['write_content_description'] = $GLOBALS['novamira_test_abilities']['novamira/gutenberg-write-content']['description'];

            $raw_rows = [
                [
                    'block_name' => 'divi/section',
                    'path' => 'block_spec[0]',
                    'category' => 'registration',
                    'code' => 'missing_block_registration',
                    'message' => 'Block "divi/section" was not registered in the block editor runtime.',
                ],
                [
                    'block_name' => 'uagb/container',
                    'path' => 'block_spec[1]',
                    'category' => 'registration',
                    'code' => 'missing_block_registration',
                    'message' => 'Block "uagb/container" was not registered in the block editor runtime.',
                ],
                [
                    'block_name' => 'divi/text',
                    'path' => 'block_spec[2]',
                    'category' => 'validation',
                    'code' => 'block_validation_failed',
                    'message' => 'Block "divi/text" failed validation.',
                ],
            ];
            $gb = 'Novamira\Abilities\Gutenberg\\';
            $registration_row = static fn(string $name, int $index): array => [
                'block_name' => $name,
                'path' => sprintf('block_spec[%d]', $index),
                'category' => 'registration',
                'code' => 'missing_block_registration',
                'message' => sprintf('Block "%s" was not registered in the block editor runtime.', $name),
            ];
            $failure_message = 'One or more Gutenberg blocks were not registered in the block editor runtime; canonical content was not written.';

            $out['compact_rows'] = ($gb . 'compact_validation_errors')($raw_rows);
            $out['batch_hints'] = ($gb . 'builder_registration_hints')($out['compact_rows']);
            $out['batch_hints_without_builder'] = ($gb . 'builder_registration_hints')(
                ($gb . 'compact_validation_errors')([$raw_rows[1], $raw_rows[2]]),
            );

            $once = ($gb . 'compact_validation_errors')($raw_rows);
            $twice = ($gb . 'compact_validation_errors')($once);
            $out['recompacted_rows'] = [
                'equal' => $once === $twice,
                'first_message' => $twice[0]['message'],
                'max_length' => max(array_map(static fn(array $row): int => mb_strlen($row['message']), $twice)),
            ];

            $GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces'] = static function (array $namespaces): array {
                $namespaces['divi'] = str_repeat('<b>Very Long Divi Label</b> ', 30);
                $namespaces['Bad Namespace'] = 'Dropped';
                $namespaces[str_repeat('a', 65)] = 'Dropped';
                return $namespaces;
            };
            $oversized_batch = ($gb . 'batch_failure_message')($failure_message, [$registration_row('divi/section', 0)]);
            $out['oversized_label'] = [
                'label' => ($gb . 'builder_block_namespaces')()['divi'],
                'malformed_namespaces_dropped' => array_map(
                    static fn(string $label): string => str_starts_with($label, 'Very Long') ? 'Divi' : $label,
                    ($gb . 'builder_block_namespaces')(),
                ),
                'validator_message' => $validate([$block('divi/section')])['message'],
                'row_message' => ($gb . 'compact_validation_errors')([$registration_row('divi/section', 0)])[0]['message'],
                'batch_message' => $oversized_batch,
                'batch_idempotent' => ($gb . 'batch_failure_message')($oversized_batch, [$registration_row('divi/section', 0)]) === $oversized_batch,
            ];

            $GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces'] = static function (array $namespaces): array {
                for ($i = 1; $i <= 6; ++$i) {
                    $namespaces['builder-' . $i] = str_repeat('B' . $i, 20);
                }
                return $namespaces;
            };
            $many_rows = [];
            for ($i = 1; $i <= 6; ++$i) {
                $many_rows[] = $registration_row('builder-' . $i . '/section', $i - 1);
            }
            $many_message = ($gb . 'batch_failure_message')($failure_message, $many_rows);
            $out['many_builders'] = [
                'message' => $many_message,
                'length' => mb_strlen($many_message),
                'idempotent' => ($gb . 'batch_failure_message')($many_message, $many_rows) === $many_message,
                'hint_count' => count(($gb . 'builder_registration_hints')($many_rows)),
                'included_count' => substr_count($many_message, ' blocks are registered only inside the '),
            ];
            unset($GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces']);

            $GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces'] = static function (array $namespaces): array {
                for ($i = 1; $i <= 8; ++$i) {
                    $namespaces['vendor-' . $i] = 'Vendor Builder ' . $i;
                }
                return $namespaces;
            };
            $short_rows = [];
            for ($i = 1; $i <= 8; ++$i) {
                $short_rows[] = $registration_row('vendor-' . $i . '/section', $i - 1);
            }
            $short_once = ($gb . 'batch_failure_message')('Failed.', $short_rows);
            $out['short_base_many_hints'] = [
                'once' => $short_once,
                'twice' => ($gb . 'batch_failure_message')($short_once, $short_rows),
                'included_count' => substr_count($short_once, ' blocks are registered only inside the '),
            ];
            unset($GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces']);

            $phrase_batch_base = 'Serializer said: foo/* blocks are registered only inside the Foo builder runtime and cannot be finalized by the Block Editor Queue; use the dedicated Foo abilities. Then it stopped.';
            $phrase_batch_once = ($gb . 'batch_failure_message')($phrase_batch_base, [$registration_row('divi/section', 0)]);
            $phrase_row_base = 'foo/* blocks are registered only inside the Foo runtime; use the dedicated Foo abilities. Real error.';
            $phrase_row = ['block_name' => 'divi/section', 'code' => 'missing_block_registration', 'message' => $phrase_row_base];
            $phrase_row_once = ($gb . 'compact_validation_errors')([$phrase_row]);
            $out['phrase_in_message'] = [
                'batch_base' => $phrase_batch_base,
                'batch_once' => $phrase_batch_once,
                'batch_twice' => ($gb . 'batch_failure_message')($phrase_batch_once, [$registration_row('divi/section', 0)]),
                'row_base' => $phrase_row_base,
                'row_once' => $phrase_row_once[0]['message'],
                'row_twice' => ($gb . 'compact_validation_errors')($phrase_row_once)[0]['message'],
            ];

            $divi_hint = ($gb . 'builder_registration_hint')('divi/section');
            $GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces'] = static function (array $namespaces): array {
                $namespaces['acme'] = 'Acme';
                return $namespaces;
            };
            $acme_hint = ($gb . 'builder_registration_hint')('acme/section');
            $degenerate_rows = [$registration_row('divi/section', 0), $registration_row('acme/section', 1)];
            $out['degenerate_bases'] = ['batch' => [], 'row' => []];
            foreach (['empty' => '', 'whitespace' => "  \n\t ", 'one_hint' => $divi_hint, 'two_hints' => $divi_hint . ' ' . $acme_hint] as $case => $base) {
                $once = ($gb . 'batch_failure_message')($base, $degenerate_rows);
                $out['degenerate_bases']['batch'][$case] = [
                    'once' => $once,
                    'twice' => ($gb . 'batch_failure_message')($once, $degenerate_rows),
                ];
            }
            foreach (['empty' => '', 'whitespace' => '   ', 'one_hint' => $divi_hint] as $case => $base) {
                $row = ['block_name' => 'divi/section', 'code' => 'missing_block_registration', 'message' => $base];
                $once = ($gb . 'compact_validation_errors')([$row]);
                $out['degenerate_bases']['row'][$case] = [
                    'once' => $once[0]['message'],
                    'twice' => ($gb . 'compact_validation_errors')($once)[0]['message'],
                ];
            }

            unset($GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces']);

            $row_at_bound = substr(str_repeat('Row message at the bound. ', 20), 0, 300);
            $batch_at_bound = substr(str_repeat('Batch message at the bound. ', 50), 0, 1000);
            $out['no_hints_at_bound'] = [
                'row_base' => $row_at_bound,
                'row_validation' => ($gb . 'compact_validation_errors')([
                    ['block_name' => 'uagb/container', 'code' => 'block_validation_failed', 'message' => $row_at_bound],
                ])[0]['message'],
                'row_registration' => ($gb . 'compact_validation_errors')([
                    ['block_name' => 'uagb/container', 'code' => 'missing_block_registration', 'message' => $row_at_bound],
                ])[0]['message'],
                'batch_base' => $batch_at_bound,
                'batch' => ($gb . 'batch_failure_message')($batch_at_bound, [$registration_row('uagb/container', 0)]),
            ];

            $GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces'] = static fn(array $namespaces): array => [
                'dashes' => '---',
                'symbols' => "&+'",
                'boolean' => true,
                'number' => 42,
                'mixed' => ' <em>Acme</em>   2 ',
            ];
            $out['unusable_labels'] = ($gb . 'builder_block_namespaces')();
            unset($GLOBALS['novamira_test_filters']['novamira_gutenberg_builder_block_namespaces']);

            $late_rows = [];
            foreach (['uagb/container', 'kadence/rowlayout', 'generateblocks/container', 'uagb/heading', 'kadence/column', 'generateblocks/headline'] as $index => $name) {
                $late_rows[] = $registration_row($name, $index);
            }
            $late_rows[] = $registration_row('divi/section', 6);
            $out['late_builder_row'] = [
                'compact_rows' => ($gb . 'compact_validation_errors')($late_rows),
                'hints' => ($gb . 'builder_registration_hints')($late_rows),
            ];

            $queue_type = \Novamira\Abilities\Gutenberg\POST_TYPE;
            $GLOBALS['novamira_test_posts'][10] = new WP_Post(10, $queue_type, 'Batch', '', 0, '2026-01-01 00:00:00');
            $GLOBALS['novamira_test_posts'][11] = new WP_Post(11, $queue_type, '', '[{"name":"core/paragraph"}]', 10, '2026-01-01 00:00:00');
            $GLOBALS['novamira_test_meta'][10] = [
                \Novamira\Abilities\Gutenberg\META_KIND => \Novamira\Abilities\Gutenberg\KIND_BATCH,
                \Novamira\Abilities\Gutenberg\META_STATUS => \Novamira\Abilities\Gutenberg\STATUS_RUNNING,
            ];
            $GLOBALS['novamira_test_meta'][11] = [
                \Novamira\Abilities\Gutenberg\META_KIND => \Novamira\Abilities\Gutenberg\KIND_ITEM,
                \Novamira\Abilities\Gutenberg\META_STATUS => \Novamira\Abilities\Gutenberg\STATUS_RUNNING,
                \Novamira\Abilities\Gutenberg\META_TARGET_ID => 1,
                \Novamira\Abilities\Gutenberg\META_TARGET_TYPE => 'page',
                \Novamira\Abilities\Gutenberg\META_LEASE_OWNER => 'lease-x',
                \Novamira\Abilities\Gutenberg\META_LEASE_EXPIRES_AT => gmdate('Y-m-d H:i:s', time() + 300),
            ];
            $failed = ($gb . 'fail_item')(11, 'lease-x', $late_rows, $failure_message, ['runtime' => 'iframe', 'reason' => '']);
            $out['fail_item'] = $failed instanceof WP_Error ? $shape($failed) : [
                'done' => $failed['done'],
                'batch_status' => $failed['batch']['status'],
                'item_status' => $failed['item']['status'],
                'last_error' => $failed['batch']['last_error'],
                'stored_last_error' => $GLOBALS['novamira_test_meta'][10][\Novamira\Abilities\Gutenberg\META_LAST_ERROR],
                'validation_errors' => $failed['item']['validation_errors'],
            ];

            echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
