<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The WordPress surface the OAuth client store, its installer and the Troubleshoot storage checks
 * touch: a wpdb double with an in-memory schema, options, transients, and the HTTP and REST shapes.
 *
 * Loaded at runtime from setUp() in a separate process (the pattern of early-rewrite-stubs.php), so
 * PHPUnit discovery never declares the wpdb class or ABSPATH in the parent process, where other
 * test files define their own stubs.
 *
 * The schema lives in the double as table name => column name => column definition, and an insert
 * naming a column its table does not have is refused exactly as MySQL refuses it. That is what makes
 * the drift this suite is about reproducible without a database.
 *
 * Schema changes arrive two ways, and the double answers both: `ALTER TABLE ... ADD COLUMN` (what
 * repair_missing_columns() issues) adds the single column it names, fails on a duplicate,
 * and is refused when the database user may not ALTER — it has no way to express a change to an
 * existing column, which is the guarantee the repair relies on, and any other ALTER reaching it
 * throws rather than being quietly ignored; dbDelta (what an install from nothing submits, see
 * fixtures/wp-root/wp-admin/includes/upgrade.php) creates a table, adds missing columns, and changes
 * an existing column whose declared type differs.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/wp-root/');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86_400);
    }

    // Keep the error_log() calls of the code under test out of the suite output, and readable by
    // the tests that assert a storage failure was recorded.
    if (!isset($GLOBALS['nm_error_log'])) {
        $GLOBALS['nm_error_log'] = (string) tempnam(sys_get_temp_dir(), 'nm-oauth-log-');
        ini_set('error_log', $GLOBALS['nm_error_log']);
    }

    if (!class_exists('wpdb')) {
        /**
         * In-memory stand-in for WordPress' database layer.
         *
         * Only the surface the OAuth code uses is modelled: one row set per table, a column list per
         * table, and the handful of query shapes the repositories and checks issue. Queries are
         * recognized by shape; the values they were prepared with are read back from $last_args
         * rather than parsed out of the SQL string.
         */
        class wpdb
        {
            public string $prefix = 'wp_';

            public string $last_error = '';

            /** @var array<string, array<string, string>> Table name => column name => its definition. */
            public array $tables = [];

            /** @var array<string, list<array<string, mixed>>> Table name => its rows. */
            public array $rows = [];

            /** @var list<mixed> Values the most recent prepare() was given. */
            public array $last_args = [];

            /** @var list<string> Every query passed to query(). */
            public array $queries = [];

            /** @var list<string> Every read this double answered, so a test can assert none happened. */
            public array $reads = [];

            /** Whether a database error would be printed, as wpdb's own property of this name is. */
            public bool $show_errors = false;

            /** @var list<bool> The display setting in force for each statement passed to query(). */
            public array $query_showing_errors = [];

            public function hide_errors(): bool
            {
                $previous = $this->show_errors;
                $this->show_errors = false;
                return $previous;
            }

            public function show_errors(bool $show = true): bool
            {
                $previous = $this->show_errors;
                $this->show_errors = $show;
                return $previous;
            }

            public function get_charset_collate(): string
            {
                return '';
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            public function prepare(string $query, mixed ...$args): string
            {
                $this->last_args = $args;
                foreach ($args as $arg) {
                    $value = is_int($arg) || is_float($arg) ? (string) $arg : "'" . (string) $arg . "'";
                    $query = preg_replace('/%[sdf]/', $value, $query, limit: 1) ?? $query;
                }
                return $query;
            }

            /** @var list<string> The table of every insert attempted, successful or not. */
            public array $inserts = [];

            /** @param array<string, mixed> $data */
            public function insert(string $table, array $data, mixed $format = null): int|false
            {
                $this->inserts[] = $table;
                // wpdb::insert() reports rows affected, so a write can also come back as 0 rather
                // than as false; $GLOBALS['nm_insert_result'] produces either.
                if (array_key_exists('nm_insert_result', $GLOBALS)) {
                    $this->last_error = 'insert reported no rows';
                    /** @var int|false $result */
                    $result = $GLOBALS['nm_insert_result'];
                    return $result;
                }
                if (!isset($this->tables[$table])) {
                    $this->last_error = sprintf("Table '%s' doesn't exist", $table);
                    return false;
                }
                foreach (array_keys($data) as $column) {
                    if (array_key_exists($column, $this->tables[$table])) {
                        continue;
                    }
                    $this->last_error = sprintf("Unknown column '%s' in 'field list'", $column);
                    return false;
                }
                $this->rows[$table][] = $data;
                $this->last_error = '';
                return 1;
            }

            /**
             * @param array<string, mixed> $data
             * @param array<string, mixed> $where
             */
            public function update(string $table, array $data, array $where): int|false
            {
                if (!isset($this->tables[$table])) {
                    $this->last_error = sprintf("Table '%s' doesn't exist", $table);
                    return false;
                }
                $updated = 0;
                foreach ($this->rows[$table] ?? [] as $index => $row) {
                    if (!$this->matches($row, $where)) {
                        continue;
                    }
                    $this->rows[$table][$index] = array_merge($row, $data);
                    $updated++;
                }
                return $updated;
            }

            /** @param array<string, mixed> $where */
            public function delete(string $table, array $where): int|false
            {
                if (!isset($this->tables[$table])) {
                    $this->last_error = sprintf("Table '%s' doesn't exist", $table);
                    return false;
                }
                $kept = [];
                $deleted = 0;
                foreach ($this->rows[$table] ?? [] as $row) {
                    if ($this->matches($row, $where)) {
                        $deleted++;
                        continue;
                    }
                    $kept[] = $row;
                }
                $this->rows[$table] = $kept;
                return $deleted;
            }

            public function query(string $query): int|false
            {
                $this->queries[] = $query;
                $this->query_showing_errors[] = $this->show_errors;
                if (preg_match('/^ALTER TABLE/i', $query) !== 1) {
                    return 0;
                }
                // The repair may only add columns. Anything else — CHANGE, MODIFY, DROP — is a
                // statement this double has no business applying, and failing loudly here is what
                // keeps a regression to one from passing unnoticed through these tests.
                if (preg_match('/^ALTER TABLE `?([A-Za-z0-9_]+)`?\s+ADD COLUMN\s+(\S+)\s*(.*)$/is', $query, $m) !== 1) {
                    throw new RuntimeException('The schema may only be changed by adding a column: ' . $query);
                }
                return $this->add_column($m[1], strtolower($m[2]), trim($m[3]));
            }

            /**
             * `ALTER TABLE ... ADD COLUMN`: adds the one column it names, or fails.
             *
             * It cannot express a change to an existing column, which is the point of the statement
             * the repair uses — a column that is already there makes it fail (MySQL refuses a
             * duplicate) and the table is left as it was. A database user who may not ALTER is
             * refused outright, like every other schema change on such a host.
             */
            private function add_column(string $table, string $column, string $definition): int|false
            {
                if (!isset($this->tables[$table])) {
                    $this->last_error = sprintf("Table '%s' doesn't exist", $table);
                    return false;
                }
                if (!(bool) ($GLOBALS['nm_test_can_alter'] ?? true)) {
                    $this->last_error = sprintf("ALTER command denied to user for table '%s'", $table);
                    return false;
                }
                if (array_key_exists($column, $this->tables[$table])) {
                    $this->last_error = sprintf("Duplicate column name '%s'", $column);
                    return false;
                }
                $this->tables[$table][$column] = $definition;
                $GLOBALS['nm_added_columns'][] = ['table' => $table, 'column' => $column];
                return 1;
            }

            public function get_var(string $query): mixed
            {
                $this->reads[] = $query;
                if (str_contains($query, 'SHOW TABLES LIKE')) {
                    $name = stripslashes((string) ($this->last_args[0] ?? ''));
                    return isset($this->tables[$name]) ? $name : null;
                }
                if (str_contains($query, 'COUNT(*)')) {
                    return (string) count($this->rows[$this->table_in($query)] ?? []);
                }
                $row = $this->find_by_client_id($this->table_in($query));
                if ($row === null) {
                    return null;
                }
                if (str_contains($query, 'SELECT grant_types')) {
                    return $row['grant_types'] ?? null;
                }
                if (str_contains($query, 'SELECT client_id')) {
                    return $row['client_id'] ?? null;
                }
                return null;
            }

            /** @return array<string, mixed>|null */
            public function get_row(string $query, mixed $output = null): ?array
            {
                $this->reads[] = $query;
                return $this->find_by_client_id($this->table_in($query));
            }

            /** @return list<array<string, mixed>> */
            public function get_results(string $query, mixed $output = null): array
            {
                $this->reads[] = $query;
                return $this->rows[$this->table_in($query)] ?? [];
            }

            /** @return list<string> */
            public function get_col(string $query, int $column = 0): array
            {
                $this->reads[] = $query;
                if (!str_contains($query, 'SHOW COLUMNS FROM')) {
                    return [];
                }
                // A table nobody can describe answers nothing at all, which is also how a failed
                // metadata query comes back: $GLOBALS['nm_unreadable_tables'] models that.
                $table = trim($this->table_in($query), '`');
                if (in_array($table, (array) ($GLOBALS['nm_unreadable_tables'] ?? []), strict: true)) {
                    return [];
                }
                return array_keys($this->tables[$table] ?? []);
            }

            /** The first table name mentioned in a query. */
            private function table_in(string $query): string
            {
                return preg_match('/' . preg_quote($this->prefix, '/') . '[a-z0-9_]+/', $query, $m) === 1 ? $m[0] : '';
            }

            /**
             * The row the query was prepared for, matched on client_id: every single-row read the
             * OAuth code issues against these tables is keyed by it.
             *
             * $GLOBALS['nm_lagging_reads'] answers that many reads as a miss before the row shows
             * up, which is what a read answered by a replica that has not caught up with the write
             * looks like.
             *
             * @return array<string, mixed>|null
             */
            private function find_by_client_id(string $table): ?array
            {
                $lagging = (int) ($GLOBALS['nm_lagging_reads'] ?? 0);
                if ($lagging > 0) {
                    $GLOBALS['nm_lagging_reads'] = $lagging - 1;
                    return null;
                }
                $client_id = (string) ($this->last_args[0] ?? '');
                foreach ($this->rows[$table] ?? [] as $row) {
                    if ((string) ($row['client_id'] ?? '') === $client_id) {
                        return $row;
                    }
                }
                return null;
            }

            /**
             * @param array<string, mixed> $row
             * @param array<string, mixed> $where
             */
            private function matches(array $row, array $where): bool
            {
                foreach ($where as $column => $value) {
                    if ((string) ($row[$column] ?? '') !== (string) $value) {
                        return false;
                    }
                }
                return true;
            }
        }
    }

    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new wpdb();
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
            public function __construct(public mixed $data = null, public int $status = 200)
            {
            }

            public function get_data(): mixed
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /**
             * @param array<string, mixed>|null $json
             * @param array<string, string> $headers
             */
            public function __construct(private ?array $json = null, private array $headers = [])
            {
            }

            /** @return array<string, mixed>|null */
            public function get_json_params(): ?array
            {
                return $this->json;
            }

            public function get_header(string $name): ?string
            {
                return $this->headers[strtolower($name)] ?? null;
            }
        }
    }

    if (!function_exists('__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }
    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES);
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $str): string
        {
            return trim((string) preg_replace('/[\r\n\t]+/', ' ', strip_tags($str)));
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode(mixed $data): string|false
        {
            return json_encode($data);
        }
    }
    if (!function_exists('add_action')) {
        function add_action(
            string $hook_name,
            callable|string $callback,
            int $priority = 10,
            int $accepted_args = 1,
        ): bool {
            $GLOBALS['nm_actions'][] = [$hook_name, $callback, $priority, $accepted_args];
            return true;
        }
    }
    if (!function_exists('add_filter')) {
        function add_filter(
            string $hook_name,
            callable|string $callback,
            int $priority = 10,
            int $accepted_args = 1,
        ): bool {
            $GLOBALS['nm_filters'][] = [$hook_name, $callback, $priority, $accepted_args];
            return true;
        }
    }
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook_name, mixed $value, mixed ...$args): mixed
        {
            return $value;
        }
    }
    if (!function_exists('get_option')) {
        function get_option(string $option, mixed $default_value = false): mixed
        {
            $GLOBALS['nm_option_reads'][] = $option;
            return $GLOBALS['nm_options'][$option] ?? $default_value;
        }
    }
    if (!function_exists('update_option')) {
        function update_option(string $option, mixed $value, ?bool $autoload = null): bool
        {
            $GLOBALS['nm_options'][$option] = $value;
            return true;
        }
    }
    if (!function_exists('delete_option')) {
        function delete_option(string $option): bool
        {
            unset($GLOBALS['nm_options'][$option]);
            return true;
        }
    }
    if (!function_exists('get_transient')) {
        function get_transient(string $transient): mixed
        {
            $GLOBALS['nm_transient_calls'][] = $transient;
            return $GLOBALS['nm_transients'][$transient] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        function set_transient(string $transient, mixed $value, int $expiration = 0): bool
        {
            $GLOBALS['nm_transient_calls'][] = $transient;
            $GLOBALS['nm_transients'][$transient] = $value;
            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $transient): bool
        {
            $GLOBALS['nm_transient_calls'][] = $transient;
            unset($GLOBALS['nm_transients'][$transient]);
            return true;
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled(string $hook): int|false
        {
            return false;
        }
    }
    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
        {
            $GLOBALS['nm_scheduled'][] = [$timestamp, $recurrence, $hook];
            return true;
        }
    }
    if (!function_exists('wp_generate_password')) {
        function wp_generate_password(
            int $length = 12,
            bool $special_chars = true,
            bool $extra_special_chars = false,
        ): string {
            return substr(str_repeat(bin2hex(random_bytes(16)), times: 3), offset: 0, length: $length);
        }
    }
    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://example.test' . $path;
        }
    }
    if (!function_exists('rest_url')) {
        function rest_url(string $path = ''): string
        {
            return 'https://example.test/wp-json/' . ltrim($path, characters: '/');
        }
    }
    if (!function_exists('novamira_likely_self_signed_https')) {
        function novamira_likely_self_signed_https(): bool
        {
            return false;
        }
    }
    if (!function_exists('novamira_is_enabled')) {
        function novamira_is_enabled(): bool
        {
            return (bool) ($GLOBALS['nm_abilities_enabled'] ?? true);
        }
    }
    if (!function_exists('novamira_oauth_transport_allowed')) {
        function novamira_oauth_transport_allowed(): bool
        {
            return (bool) ($GLOBALS['nm_transport_allowed'] ?? true);
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error(mixed $thing): bool
        {
            return $thing instanceof WP_Error;
        }
    }

    /**
     * The registration probe's HTTP layer, driven by $GLOBALS['nm_registration_response']:
     * a code/body pair, or ['error' => message] for a transport failure. 'store_client' writes the
     * reported client into the store first, standing in for an endpoint whose insert did land.
     */
    if (!function_exists('wp_remote_post')) {
        function wp_remote_post(string $url, array $args = []): mixed
        {
            /** @var array<string, mixed> $response */
            $response = $GLOBALS['nm_registration_response'] ?? ['code' => 201, 'body' => ''];
            $GLOBALS['nm_registration_requests'][] = ['url' => $url, 'args' => $args];
            if (array_key_exists('error', $response)) {
                return new WP_Error('http_request_failed', (string) $response['error']);
            }
            if ((bool) ($response['store_client'] ?? false)) {
                /** @var array<string, mixed> $body */
                $body = json_decode((string) $response['body'], associative: true);
                nm_test_store_client((string) $body['client_id']);
            }
            return $response;
        }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code(mixed $response): int
        {
            return is_array($response) ? (int) ($response['code'] ?? 0) : 0;
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body(mixed $response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }

    /** Writes a client row straight into the store, bypassing the registration endpoint. */
    function nm_test_store_client(string $client_id, string $client_name = 'Stored Client'): void
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->insert($wpdb->prefix . 'novamira_oauth_clients', [
            'client_id' => $client_id,
            'client_name' => $client_name,
            'redirect_uris' => (string) json_encode(['https://example.test/cb']),
            'is_confidential' => 0,
            'client_secret_hash' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'last_used_at' => null,
            'registered_by_ip_hash' => hash('sha256', '203.0.113.5'),
            'admin_created' => 0,
            'grant_types' => (string) json_encode(['authorization_code', 'refresh_token']),
        ]);
    }

    /** The prefix every OAuth table name is built from, as the installer builds it. */
    function nm_test_oauth_prefix(): string
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->prefix . 'novamira_oauth_';
    }

    /** The clients table of the store, as the repositories name it. */
    function nm_test_clients_table(): string
    {
        return nm_test_oauth_prefix() . 'clients';
    }

    /**
     * Leaves behind the clients table an older schema version created — missing the named columns —
     * on a site whose database user may not ALTER tables. The installer can then create every other
     * table but never complete this one, which is the site the fix is about.
     *
     * Built by running the installer and taking the columns away again, so the table is exactly the
     * one the CREATE TABLE statements produce, minus the migration that did not apply. $recorded is
     * the schema version the site carries afterwards: '4' is a site the previous release installed —
     * the version says it is done, and the table says otherwise — and false is a site with nothing
     * recorded at all.
     *
     * @param list<string> $missing_columns
     */
    function nm_test_seed_incomplete_clients_table(array $missing_columns, string|false $recorded = '4'): void
    {
        \Novamira\OAuth\Schema\maybe_install();
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $table = nm_test_clients_table();
        foreach ($missing_columns as $column) {
            unset($wpdb->tables[$table][$column]);
        }
        if ($recorded === false) {
            delete_option(\Novamira\OAuth\Schema\SCHEMA_VERSION_OPTION);
        } else {
            update_option(\Novamira\OAuth\Schema\SCHEMA_VERSION_OPTION, $recorded);
        }
        $GLOBALS['nm_test_can_alter'] = false;
        nm_test_forget_schema_activity();
    }

    /** Clears the record of what the code under test has read and written so far. */
    function nm_test_forget_schema_activity(): void
    {
        $GLOBALS['nm_option_reads'] = [];
        $GLOBALS['nm_transient_calls'] = [];
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->inserts = [];
        $wpdb->reads = [];
        $wpdb->queries = [];
        $wpdb->query_showing_errors = [];
        $GLOBALS['nm_dbdelta_calls'] = [];
        $GLOBALS['nm_column_changes'] = [];
        $GLOBALS['nm_added_columns'] = [];
    }

    /**
     * The column definitions of one table in the store, as the double holds them.
     *
     * @return array<string, string>
     */
    function nm_test_columns(string $table): array
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        return $wpdb->tables[$table] ?? [];
    }

    /** Everything the code under test reads or writes between tests. */
    function nm_test_reset_state(): void
    {
        $GLOBALS['wpdb'] = new wpdb();
        $GLOBALS['nm_options'] = [];
        $GLOBALS['nm_transients'] = [];
        $GLOBALS['nm_dbdelta_calls'] = [];
        $GLOBALS['nm_column_changes'] = [];
        $GLOBALS['nm_added_columns'] = [];
        $GLOBALS['nm_registration_requests'] = [];
        $GLOBALS['nm_test_can_alter'] = true;
        $GLOBALS['nm_unreadable_tables'] = [];
        $GLOBALS['nm_lagging_reads'] = 0;
        $GLOBALS['nm_option_reads'] = [];
        $GLOBALS['nm_transient_calls'] = [];
        $GLOBALS['nm_dbdelta_cannot_create'] = [];
        unset($GLOBALS['nm_registration_response'], $GLOBALS['nm_insert_result']);
        file_put_contents((string) $GLOBALS['nm_error_log'], data: '');
    }

    /** What the code under test wrote to the PHP error log during this test. */
    function nm_test_error_log(): string
    {
        return (string) file_get_contents((string) $GLOBALS['nm_error_log']);
    }
}
