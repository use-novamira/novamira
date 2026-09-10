<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}
if (!function_exists('add_action')) {
    function add_action(
        string $hook_name,
        callable|string $callback,
        int $priority = 10,
        int $accepted_args = 1,
    ): bool {
        $GLOBALS['novamira_test_actions'][] = [$hook_name, $callback, $priority, $accepted_args];
        return true;
    }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $GLOBALS['novamira_test_translations'][$domain][$text] ?? $text;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool
    {
        return $value instanceof WP_Error;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callback = $GLOBALS['novamira_test_filter_callbacks'][$hook] ?? null;
        return is_callable($callback) ? $callback($value, ...$args) : $value;
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.test/wp-json/' . ltrim($path, characters: '/');
    }
}
if (!function_exists('urlencode_deep')) {
    function urlencode_deep(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('urlencode_deep', $value);
        }

        return is_string($value) ? urlencode($value) : $value;
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(array|string $key, mixed $value = '', string $url = ''): string
    {
        $args = is_array($key) ? $key : [$key => $value];
        $target = is_array($key) ? (string) $value : $url;
        $query = [];
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        foreach ($args as $name => $argument) {
            $query[(string) $name] = $argument;
        }

        $base = strtok($target, '?');
        $base = $base === false ? $target : $base;
        return $query === [] ? $base : $base . '?' . http_build_query($query);
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route(string $route_namespace, string $route, array $args): bool
    {
        $GLOBALS['novamira_test_rest_routes'][] = [$route_namespace, $route, $args];
        return true;
    }
}
if (!function_exists('novamira_is_enabled')) {
    function novamira_is_enabled(): bool
    {
        return (bool) ($GLOBALS['novamira_test_enabled'] ?? false);
    }
}
if (!function_exists('novamira_current_user_can_manage')) {
    function novamira_current_user_can_manage(): bool
    {
        $GLOBALS['novamira_test_current_user_can_manage_calls'] =
            ($GLOBALS['novamira_test_current_user_can_manage_calls'] ?? 0) + 1;
        return (bool) ($GLOBALS['novamira_test_current_user_can_manage'] ?? false);
    }
}
if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const CREATABLE = 'POST';
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param array<string, mixed> $data */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private array $data = [],
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return array<string, mixed> */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var array<string, string> */
        public array $headers = [];

        public function __construct(public mixed $data = null, public int $status = 200)
        {
        }

        public function header(string $name, string $value, bool $replace = true): void
        {
            if (!$replace && isset($this->headers[$name])) {
                $this->headers[$name] .= ', ' . $value;
                return;
            }

            $this->headers[$name] = $value;
        }

        public function link_header(string $rel, string $link): void
        {
            $this->header('Link', '<' . $link . '>; rel="' . $rel . '"', replace: false);
        }

        /** @return array<string, string> */
        public function get_headers(): array
        {
            return $this->headers;
        }

        /** @param array<string, string> $headers */
        public function set_headers(array $headers): void
        {
            $this->headers = $headers;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function set_data(mixed $data): void
        {
            $this->data = $data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}
if (!class_exists('WP_Ability')) {
    class WP_Ability
    {
        /** @param array<string, mixed> $meta */
        public function __construct(
            private array $meta,
            private mixed $result = null,
            private string $name = '',
            private string $category = '',
        ) {}

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_category(): string
        {
            return $this->category;
        }

        public function get_meta_item(string $key, mixed $default = null): mixed
        {
            return $this->meta[$key] ?? $default;
        }

        public function execute(mixed $input = null): mixed
        {
            return $this->result instanceof Closure ? ($this->result)($input) : $this->result;
        }
    }
}
if (!function_exists('wp_get_ability')) {
    function wp_get_ability(string $name): mixed
    {
        return $GLOBALS['novamira_test_abilities'][$name] ?? null;
    }
}
if (!function_exists('wp_get_abilities')) {
    /** @return array<string, WP_Ability> */
    function wp_get_abilities(): array
    {
        return $GLOBALS['novamira_test_abilities'];
    }
}
if (!class_exists('WP_Ability_Category')) {
    class WP_Ability_Category
    {
        public function __construct(private string $slug)
        {
        }

        public function get_slug(): string
        {
            return $this->slug;
        }
    }
}
if (!function_exists('wp_get_ability_categories')) {
    /** @return array<string, WP_Ability_Category> */
    function wp_get_ability_categories(): array
    {
        return $GLOBALS['novamira_test_ability_categories'];
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess
    {
        /** @param array<string, mixed>|null $json */
        public function __construct(
            private string $method = 'GET',
            private string $route = '',
            private string $body = '',
            private ?array $json = null,
            private array $params = [],
        ) {
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        /** @return array<string, mixed>|null */
        public function get_json_params(): ?array
        {
            return $this->json;
        }

        /** @return array<string, mixed> */
        public function get_query_params(): array
        {
            return $this->params;
        }

        public function offsetExists(mixed $offset): bool
        {
            return is_string($offset) && array_key_exists($offset, $this->params);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return is_string($offset) ? ($this->params[$offset] ?? null) : null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            if (is_string($offset)) {
                $this->params[$offset] = $value;
            }
        }

        public function offsetUnset(mixed $offset): void
        {
            if (is_string($offset)) {
                unset($this->params[$offset]);
            }
        }
    }
}
if (!class_exists('WP_REST_Abilities_V1_List_Controller')) {
    class WP_REST_Abilities_V1_List_Controller
    {
        public function prepare_item_for_response(WP_Ability $ability, WP_REST_Request $request): WP_REST_Response
        {
            return new WP_REST_Response([
                'name' => $ability->get_name(),
                'category' => $ability->get_category(),
                'meta' => ['show_in_rest' => $ability->get_meta_item('show_in_rest')],
            ]);
        }

        /** @return array<string, mixed> */
        public function prepare_response_for_collection(WP_REST_Response $response): array
        {
            $data = $response->get_data();
            return is_array($data) ? $data : [];
        }
    }
}
if (!class_exists('WP_REST_Abilities_V1_Run_Controller')) {
    class WP_REST_Abilities_V1_Run_Controller
    {
    }
}
if (!class_exists('WP_REST_Abilities_V1_Categories_Controller')) {
    class WP_REST_Abilities_V1_Categories_Controller
    {
        public function prepare_item_for_response(
            WP_Ability_Category $category,
            WP_REST_Request $request,
        ): WP_REST_Response {
            return new WP_REST_Response(['slug' => $category->get_slug()]);
        }

        /** @return array<string, mixed> */
        public function prepare_response_for_collection(WP_REST_Response $response): array
        {
            $data = $response->get_data();
            return is_array($data) ? $data : [];
        }
    }
}

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/ability-metadata.php';
require_once __DIR__ . '/../../includes/rest-shim.php';

final class RestAbilitySurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['novamira_test_enabled'] = true;
        $GLOBALS['novamira_test_current_user_can_manage'] = true;
        $GLOBALS['novamira_test_current_user_can_manage_calls'] = 0;
        $GLOBALS['novamira_test_filter_callbacks'] = [];
        $GLOBALS['novamira_test_translations'] = [];
        $GLOBALS['novamira_test_rest_routes'] = [];
        $GLOBALS['novamira_test_abilities'] = [];
        $GLOBALS['novamira_test_ability_categories'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['novamira_test_enabled'],
            $GLOBALS['novamira_test_current_user_can_manage'],
            $GLOBALS['novamira_test_current_user_can_manage_calls'],
            $GLOBALS['novamira_test_filter_callbacks'],
            $GLOBALS['novamira_test_translations'],
            $GLOBALS['novamira_test_rest_routes'],
            $GLOBALS['novamira_test_abilities'],
            $GLOBALS['novamira_test_ability_categories'],
        );
    }

    public function testRegistersCompleteAbilityNameAndInputSchema(): void
    {
        novamira_register_ability_run_rest_shim();

        self::assertCount(1, $GLOBALS['novamira_test_rest_routes']);
        [$namespace, $route, $args] = $GLOBALS['novamira_test_rest_routes'][0];
        self::assertSame('novamira/v1', $namespace);
        self::assertStringContainsString('(?P<ability_name>', $route);
        self::assertArrayHasKey('input', $args['args']);
        self::assertFalse($args['args']['input']['required']);
        self::assertSame('^[a-z0-9-]+(?:/[a-z0-9-]+)+$', $args['args']['ability_name']['pattern']);
    }

    #[DataProvider('validBodyProvider')]
    public function testBodyContractAcceptsOnlyObjectWithArbitraryInput(
        string $raw,
        ?array $json,
        mixed $expected,
    ): void {
        $request = new WP_REST_Request('POST', '/run', $raw, $json);
        self::assertSame($expected, novamira_rest_run_input($request));
    }

    /** @return iterable<string, array{string, array<string, mixed>|null, mixed}> */
    public static function validBodyProvider(): iterable
    {
        yield 'empty body' => ['', null, null];
        yield 'empty object' => ['{}', [], null];
        yield 'null input' => ['{"input":null}', ['input' => null], null];
        yield 'object input' => ['{"input":{"key":"value"}}', ['input' => ['key' => 'value']], ['key' => 'value']];
        yield 'array input' => ['{"input":[1,2]}', ['input' => [1, 2]], [1, 2]];
        yield 'scalar input' => ['{"input":"value"}', ['input' => 'value'], 'value'];
        yield 'boolean input' => ['{"input":false}', ['input' => false], false];
    }

    #[DataProvider('invalidBodyProvider')]
    public function testBodyContractRejectsInvalidTopLevelAndUnexpectedFields(string $raw, ?array $json): void
    {
        $error = novamira_rest_run_input(new WP_REST_Request('POST', '/run', $raw, $json));
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame(400, $error->get_error_data()['status']);
    }

    /** @return iterable<string, array{string, array<string, mixed>|null}> */
    public static function invalidBodyProvider(): iterable
    {
        yield 'top-level array' => ['[1,2]', [1, 2]];
        yield 'top-level scalar' => ['"value"', null];
        yield 'malformed object' => ['{"input":', null];
        yield 'extra field' => ['{"input":null,"extra":true}', ['input' => null, 'extra' => true]];
        yield 'only unexpected field' => ['{"extra":true}', ['extra' => true]];
    }

    #[DataProvider('rawResultProvider')]
    public function testExecutionPreservesEveryRawSuccessType(mixed $result): void
    {
        $GLOBALS['novamira_test_abilities']['vendor/group/nested'] = new WP_Ability(
            ['show_in_rest' => true],
            $result,
        );
        $request = new WP_REST_Request(
            'POST',
            '/run',
            '{}',
            [],
            ['ability_name' => 'vendor/group/nested'],
        );

        self::assertSame($result, novamira_rest_run_ability($request));
    }

    /** @return iterable<string, array{mixed}> */
    public static function rawResultProvider(): iterable
    {
        yield 'scalar' => ['value'];
        yield 'array' => [[1, 2]];
        yield 'object' => [(object) ['key' => 'value']];
        yield 'boolean' => [true];
        yield 'null' => [null];
    }

    public function testHiddenMissingAndInvalidAbilityNamesFailSafely(): void
    {
        $GLOBALS['novamira_test_abilities']['vendor/hidden'] = new WP_Ability(['show_in_rest' => false]);

        $hidden = novamira_rest_run_ability($this->requestFor('vendor/hidden'));
        self::assertSame('novamira_ability_hidden', $hidden->get_error_code());
        self::assertSame(404, $hidden->get_error_data()['status']);

        $missing = novamira_rest_run_ability($this->requestFor('vendor/missing'));
        self::assertSame('novamira_ability_not_found', $missing->get_error_code());

        $invalid = novamira_rest_run_ability($this->requestFor('Vendor/invalid_name'));
        self::assertSame('novamira_invalid_ability_name', $invalid->get_error_code());
        self::assertSame(400, $invalid->get_error_data()['status']);
    }

    #[DataProvider('classifiedErrorProvider')]
    public function testClassifiesOnlyStableInputAndPermissionErrors(string $code, int $status): void
    {
        $GLOBALS['novamira_test_abilities']['vendor/error'] = new WP_Ability(
            ['show_in_rest' => true],
            new WP_Error($code, 'Failure'),
        );

        $error = novamira_rest_run_ability($this->requestFor('vendor/error'));
        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame($code, $error->get_error_code());
        self::assertSame($status, $error->get_error_data()['status']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function classifiedErrorProvider(): iterable
    {
        yield 'invalid input' => ['ability_invalid_input', 400];
        yield 'missing schema' => ['ability_missing_input_schema', 400];
        yield 'permission' => ['ability_invalid_permissions', 403];
    }

    public function testPermissionBoundaryPreservesDisabledAndCapabilityChecks(): void
    {
        $GLOBALS['novamira_test_enabled'] = false;
        $disabled = novamira_rest_run_ability_permission();
        self::assertInstanceOf(WP_Error::class, $disabled);
        self::assertSame('novamira_disabled', $disabled->get_error_code());

        $GLOBALS['novamira_test_enabled'] = true;
        $GLOBALS['novamira_test_current_user_can_manage'] = false;
        $forbidden = novamira_rest_run_ability_permission();
        self::assertInstanceOf(WP_Error::class, $forbidden);
        self::assertSame('novamira_forbidden', $forbidden->get_error_code());
    }

    public function testManagersKeepCompleteRestAbilityMetadata(): void
    {
        $list_controller = new WP_REST_Abilities_V1_List_Controller();
        $items = [
            ['name' => 'novamira/read-file', 'input_schema' => ['type' => 'object']],
            ['name' => 'novamira/write-file', 'output_schema' => ['type' => 'object']],
            ['name' => 'vendor/extension-action', 'description' => 'Third-party Ability'],
        ];
        $collection = new WP_REST_Response($items);

        self::assertSame(
            $collection,
            novamira_filter_rest_ability_metadata(
                $collection,
                $this->handler($list_controller, 'get_items'),
                $this->collectionRequest(),
            ),
        );
        self::assertSame($items, $collection->get_data());

        $item = new WP_REST_Response(['name' => 'novamira/read-file']);
        self::assertSame(
            $item,
            novamira_filter_rest_ability_metadata(
                $item,
                $this->handler($list_controller, 'get_item'),
                new WP_REST_Request(
                    'GET',
                    '/wp-abilities/v1/abilities/novamira/read-file',
                    params: ['name' => 'novamira/read-file'],
                ),
            ),
        );

        $run_controller = new WP_REST_Abilities_V1_Run_Controller();
        self::assertNull(novamira_prevent_restricted_rest_ability_run(
            null,
            $this->handler($run_controller, 'execute_ability'),
            new WP_REST_Request(
                'POST',
                '/wp-abilities/v1/abilities/novamira/read-file/run',
                params: ['name' => 'novamira/read-file'],
            ),
        ));

        $run_response = new WP_REST_Response(['executed' => true]);
        self::assertSame(
            $run_response,
            novamira_filter_rest_ability_metadata(
                $run_response,
                $this->handler($run_controller, 'execute_ability'),
                new WP_REST_Request(
                    'POST',
                    '/wp-abilities/v1/abilities/novamira/read-file/run',
                    params: ['name' => 'novamira/read-file'],
                ),
            ),
        );
    }

    public function testSubscriberCoreRunIsRefusedBeforeInputValidation(): void
    {
        $GLOBALS['novamira_test_current_user_can_manage'] = false;
        $handler = $this->handler(new WP_REST_Abilities_V1_Run_Controller(), 'execute_ability');
        $request = new WP_REST_Request(
            'GET',
            '/wp-abilities/v1/abilities/novamira/read-file/run',
            params: ['name' => 'novamira/read-file'],
        );

        $validator_reached = false;
        $response = novamira_prevent_restricted_rest_ability_run(null, $handler, $request);
        if (!$response instanceof WP_Error) {
            $validator_reached = true;
            $response = new WP_Error('ability_invalid_input', 'path is required', ['status' => 400]);
        }
        self::assertFalse($validator_reached);
        self::assertSame('rest_ability_not_found', $response->get_error_code());
        self::assertSame(__('Ability not found.'), $response->get_error_message());
        self::assertSame(404, $response->get_error_data()['status']);

        $third_party_validator_reached = false;
        $third_party_request = new WP_REST_Request(
            'GET',
            '/wp-abilities/v1/abilities/vendor/extension-action/run',
            params: ['name' => 'vendor/extension-action'],
        );
        $third_party_response = novamira_prevent_restricted_rest_ability_run(
            null,
            $handler,
            $third_party_request,
        );
        if ($third_party_response === null) {
            $third_party_validator_reached = true;
            $third_party_response = new WP_REST_Response(['executed' => true]);
        }
        self::assertTrue($third_party_validator_reached);
        self::assertInstanceOf(WP_REST_Response::class, $third_party_response);
        self::assertSame(
            $third_party_response,
            novamira_filter_rest_ability_metadata($third_party_response, $handler, $third_party_request),
        );

        $earlier_error = new WP_Error('earlier_error', 'Earlier denial', ['status' => 403]);
        self::assertSame($earlier_error, novamira_prevent_restricted_rest_ability_run($earlier_error, $handler, $request));

        $permitted = new WP_REST_Response(['executed' => true]);
        $gated = novamira_prevent_restricted_rest_ability_run($permitted, $handler, $request);
        self::assertInstanceOf(WP_Error::class, $gated);
        self::assertSame('rest_ability_not_found', $gated->get_error_code());
        self::assertSame($permitted, novamira_filter_rest_ability_metadata($permitted, $handler, $request));

        self::assertNull(novamira_prevent_restricted_rest_ability_run(
            null,
            ['callback' => 'novamira_rest_run_ability'],
            new WP_REST_Request(
                'POST',
                '/novamira/v1/abilities/novamira/read-file/run',
                params: ['ability_name' => 'novamira/read-file'],
            ),
        ));
    }

    public function testSubscriberAbilityCollectionsUseVisiblePaginationBeforeSlicing(): void
    {
        $GLOBALS['novamira_test_current_user_can_manage'] = false;
        for ($index = 1; $index <= 6; $index++) {
            $name = 'novamira/hidden-' . $index;
            $GLOBALS['novamira_test_abilities'][$name] = $this->ability($name, 'filesystem');
        }
        for ($index = 1; $index <= 5; $index++) {
            $name = 'vendor/visible-' . $index;
            $GLOBALS['novamira_test_abilities'][$name] = $this->ability($name, 'vendor');
        }

        $controller = new WP_REST_Abilities_V1_List_Controller();
        $handler = $this->handler($controller, 'get_items');
        $seen = [];
        $expected_links = [
            1 => '<https://example.test/wp-json/wp-abilities/v1/abilities?page=2&per_page=2>; rel="next"',
            2 => '<https://example.test/wp-json/wp-abilities/v1/abilities?page=1&per_page=2>; rel="prev", '
                . '<https://example.test/wp-json/wp-abilities/v1/abilities?page=3&per_page=2>; rel="next"',
            3 => '<https://example.test/wp-json/wp-abilities/v1/abilities?page=2&per_page=2>; rel="prev"',
        ];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->staleCollectionResponse(total: 11, pages: 6);
            $filtered = novamira_filter_rest_ability_metadata(
                $response,
                $handler,
                $this->collectionRequest(page: $page, perPage: 2),
            );
            self::assertSame($response, $filtered);
            self::assertSame('5', $response->headers['X-WP-Total']);
            self::assertSame('3', $response->headers['X-WP-TotalPages']);
            self::assertSame($expected_links[$page], $response->headers['Link']);

            $data = $response->get_data();
            self::assertIsArray($data);
            if ($data === []) {
                break;
            }
            $seen = array_merge($seen, array_column($data, 'name'));
        }

        self::assertSame(
            ['vendor/visible-1', 'vendor/visible-2', 'vendor/visible-3', 'vendor/visible-4', 'vendor/visible-5'],
            $seen,
        );

        $category_response = $this->staleCollectionResponse(total: 6, pages: 3);
        novamira_filter_rest_ability_metadata(
            $category_response,
            $handler,
            $this->collectionRequest(page: 1, perPage: 2, category: 'filesystem'),
        );
        self::assertSame([], $category_response->get_data());
        self::assertSame('0', $category_response->headers['X-WP-Total']);
        self::assertSame('0', $category_response->headers['X-WP-TotalPages']);
        self::assertArrayNotHasKey('Link', $category_response->headers);
    }

    public function testSubscriberAbilityItemsUseCanonicalNotFoundAndPreserveOtherResponses(): void
    {
        $GLOBALS['novamira_test_current_user_can_manage'] = false;
        $controller = new WP_REST_Abilities_V1_List_Controller();
        $handler = $this->handler($controller, 'get_item');
        $hidden = novamira_filter_rest_ability_metadata(
            new WP_REST_Response(['name' => 'novamira/read-file']),
            $handler,
            new WP_REST_Request(
                'GET',
                '/wp-abilities/v1/abilities/novamira/read-file',
                params: ['name' => 'novamira/read-file'],
            ),
        );
        self::assertInstanceOf(WP_Error::class, $hidden);
        self::assertSame('rest_ability_not_found', $hidden->get_error_code());
        self::assertSame(404, $hidden->get_error_data()['status']);
        self::assertSame(__('Ability not found.'), $hidden->get_error_message());

        $third_party = new WP_REST_Response(['name' => 'vendor/extension-action']);
        self::assertSame(
            $third_party,
            novamira_filter_rest_ability_metadata(
                $third_party,
                $handler,
                new WP_REST_Request(
                    'GET',
                    '/wp-abilities/v1/abilities/vendor/extension-action',
                    params: ['name' => 'vendor/extension-action'],
                ),
            ),
        );

        $forbidden = new WP_REST_Response(['code' => 'rest_forbidden'], status: 403);
        self::assertSame(
            $forbidden,
            novamira_filter_rest_ability_metadata(
                $forbidden,
                $handler,
                new WP_REST_Request(
                    'GET',
                    '/wp-abilities/v1/abilities/novamira/read-file',
                    params: ['name' => 'novamira/read-file'],
                ),
            ),
        );

        $lookalike = new WP_REST_Response(['schema' => 'must remain']);
        self::assertSame(
            $lookalike,
            novamira_filter_rest_ability_metadata(
                $lookalike,
                ['callback' => [new stdClass(), 'get_item']],
                new WP_REST_Request(
                    'GET',
                    '/wp-abilities/v1/abilities/novamira/read-file',
                    params: ['name' => 'novamira/read-file'],
                ),
            ),
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCanonicalNotFoundUsesTheCoreTranslationDomain(): void
    {
        $GLOBALS['novamira_test_translations'] = [
            'default' => ['Ability not found.' => 'Capacità non trovata.'],
        ];
        $error = novamira_rest_ability_not_found_error();
        $missing = new WP_Error('rest_ability_not_found', __('Ability not found.'), ['status' => 404]);
        self::assertSame('Capacità non trovata.', $missing->get_error_message());
        self::assertSame($missing->get_error_message(), $error->get_error_message());

        $source = file_get_contents(__DIR__ . '/../../includes/ability-metadata.php');
        self::assertIsString($source);
        self::assertStringContainsString("__('Ability not found.')", $source);
        self::assertStringNotContainsString("__('Ability not found.', domain:", $source);
    }

    public function testUnrelatedRestHandlersDoNotCheckNovamiraManagementPermission(): void
    {
        $GLOBALS['novamira_test_current_user_can_manage_calls'] = 0;
        $response = new WP_REST_Response(['name' => 'novamira/read-file']);

        self::assertSame(
            $response,
            novamira_filter_rest_ability_metadata(
                $response,
                ['callback' => [new stdClass(), 'get_item']],
                new WP_REST_Request('GET', '/wp/v2/posts'),
            ),
        );
        self::assertSame(0, $GLOBALS['novamira_test_current_user_can_manage_calls']);
    }

    public function testSubclassedCoreControllersAreNotFiltered(): void
    {
        $GLOBALS['novamira_test_current_user_can_manage'] = false;
        $GLOBALS['novamira_test_current_user_can_manage_calls'] = 0;
        $controller = new class() extends WP_REST_Abilities_V1_List_Controller {};
        $response = new WP_REST_Response([['name' => 'novamira/read-file']]);

        self::assertSame(
            $response,
            novamira_filter_rest_ability_metadata(
                $response,
                $this->handler($controller, 'get_items'),
                $this->collectionRequest(),
            ),
        );
        self::assertSame([['name' => 'novamira/read-file']], $response->get_data());
        self::assertSame(0, $GLOBALS['novamira_test_current_user_can_manage_calls']);

        $run_controller = new class() extends WP_REST_Abilities_V1_Run_Controller {};
        self::assertNull(novamira_prevent_restricted_rest_ability_run(
            null,
            $this->handler($run_controller, 'execute_ability'),
            new WP_REST_Request(
                'GET',
                '/vendor/v1/abilities/novamira/read-file/run',
                params: ['name' => 'novamira/read-file'],
            ),
        ));
        self::assertSame(0, $GLOBALS['novamira_test_current_user_can_manage_calls']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAbilityCategorySlugsCanBeContributedThroughTheExtensionPoint(): void
    {
        $GLOBALS['novamira_test_filter_callbacks']['novamira_ability_category_slugs'] =
            static function (array $slugs): array {
                $slugs[] = 'woocommerce';
                return $slugs;
            };

        self::assertContains('woocommerce', novamira_ability_category_slugs());
        self::assertTrue(novamira_ability_category_metadata_is_restricted('woocommerce'));
    }

    public function testSubscriberCategoryMetadataIsHiddenWithoutAffectingThirdPartyCategories(): void
    {
        $GLOBALS['novamira_test_current_user_can_manage'] = false;
        foreach (['filesystem', 'vendor-tools', 'skill', 'design-system'] as $slug) {
            $GLOBALS['novamira_test_ability_categories'][$slug] = new WP_Ability_Category($slug);
        }
        $GLOBALS['novamira_test_abilities']['vendor/shared-skill'] = $this->ability('vendor/shared-skill', 'skill');

        $controller = new WP_REST_Abilities_V1_Categories_Controller();
        $handler = $this->handler($controller, 'get_items');
        $response = $this->staleCollectionResponse(total: 4, pages: 4);
        novamira_filter_rest_ability_metadata(
            $response,
            $handler,
            $this->collectionRequest(page: 1, perPage: 1, route: '/wp-abilities/v1/categories'),
        );
        self::assertSame([['slug' => 'vendor-tools']], $response->get_data());
        self::assertSame('2', $response->headers['X-WP-Total']);
        self::assertSame('2', $response->headers['X-WP-TotalPages']);
        self::assertSame(
            '<https://example.test/wp-json/wp-abilities/v1/categories?page=2&per_page=1>; rel="next"',
            $response->headers['Link'],
        );

        $second_page = $this->staleCollectionResponse(total: 4, pages: 4);
        novamira_filter_rest_ability_metadata(
            $second_page,
            $handler,
            $this->collectionRequest(page: 2, perPage: 1, route: '/wp-abilities/v1/categories'),
        );
        self::assertSame([['slug' => 'skill']], $second_page->get_data());
        self::assertSame(
            '<https://example.test/wp-json/wp-abilities/v1/categories?page=1&per_page=1>; rel="prev"',
            $second_page->headers['Link'],
        );

        $item_handler = $this->handler($controller, 'get_item');
        $hidden = novamira_filter_rest_ability_metadata(
            new WP_REST_Response(['slug' => 'filesystem']),
            $item_handler,
            new WP_REST_Request(
                'GET',
                '/wp-abilities/v1/categories/filesystem',
                params: ['slug' => 'filesystem'],
            ),
        );
        self::assertInstanceOf(WP_Error::class, $hidden);
        self::assertSame('rest_ability_category_not_found', $hidden->get_error_code());
        self::assertSame(__('Ability category not found.'), $hidden->get_error_message());
        self::assertSame(404, $hidden->get_error_data()['status']);

        $third_party = new WP_REST_Response(['slug' => 'vendor-tools']);
        self::assertSame(
            $third_party,
            novamira_filter_rest_ability_metadata(
                $third_party,
                $item_handler,
                new WP_REST_Request(
                    'GET',
                    '/wp-abilities/v1/categories/vendor-tools',
                    params: ['slug' => 'vendor-tools'],
                ),
            ),
        );

        $shared = new WP_REST_Response(['slug' => 'skill']);
        self::assertSame(
            $shared,
            novamira_filter_rest_ability_metadata(
                $shared,
                $item_handler,
                new WP_REST_Request(
                    'GET',
                    '/wp-abilities/v1/categories/skill',
                    params: ['slug' => 'skill'],
                ),
            ),
        );
    }

    public function testManagersKeepCategoryResponsesUnchanged(): void
    {
        $controller = new WP_REST_Abilities_V1_Categories_Controller();
        $response = new WP_REST_Response([['slug' => 'filesystem']]);

        self::assertSame(
            $response,
            novamira_filter_rest_ability_metadata(
                $response,
                $this->handler($controller, 'get_items'),
                $this->collectionRequest(route: '/wp-abilities/v1/categories'),
            ),
        );
    }

    private function requestFor(string $abilityName): WP_REST_Request
    {
        return new WP_REST_Request('POST', '/run', '{}', [], ['ability_name' => $abilityName]);
    }

    private function ability(string $name, string $category): WP_Ability
    {
        return new WP_Ability(['show_in_rest' => true], name: $name, category: $category);
    }

    /** @return array{callback: array{object, string}} */
    private function handler(object $controller, string $method): array
    {
        return ['callback' => [$controller, $method]];
    }

    private function collectionRequest(
        int $page = 1,
        int $perPage = 50,
        string $category = '',
        string $route = '/wp-abilities/v1/abilities',
    ): WP_REST_Request {
        $params = ['page' => $page, 'per_page' => $perPage];
        if ($category !== '') {
            $params['category'] = $category;
        }

        return new WP_REST_Request(
            'GET',
            $route,
            params: $params,
        );
    }

    private function staleCollectionResponse(int $total, int $pages): WP_REST_Response
    {
        $response = new WP_REST_Response([]);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $pages);
        $response->header('Link', '<https://example.test/next>; rel="next"');
        return $response;
    }
}
