<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Troubleshoot\Checks;

use Novamira\OAuth\Repositories\ClientRepository;

if (!defined('ABSPATH')) {
    exit();
}

const HTTP_TIMEOUT = 5;

/**
 * Options for the self-probe HTTP requests. TLS verification stays on for public sites; on a
 * local-only HTTPS host (self-signed or local-CA certs, see novamira_likely_self_signed_https)
 * it is relaxed, otherwise every probe would fail on the certificate instead of testing anything.
 *
 * @return array{timeout: int, sslverify: bool}
 */
function http_options(): array
{
    return ['timeout' => HTTP_TIMEOUT, 'sslverify' => !\novamira_likely_self_signed_https()];
}

/**
 * Run the diagnostics in dependency order. Failed prerequisites (abilities, transport, schema)
 * mark the checks that depend on them as skipped instead of piling redundant failures onto the
 * report.
 *
 * $method scopes the report to the connection method being troubleshot: 'oauth' drops the
 * Application Passwords check, 'password' drops the OAuth-only checks (and, with them, their
 * outbound probes). Null runs everything. Abilities, transport, permalinks, and anonymous REST
 * apply to both methods.
 *
 * @return list<array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}>
 */
function run_all(?string $method = null): array
{
    // 'password' here is the connection-method slug, not a credential — nothing sensitive is compared.
    // @mago-expect lint:no-insecure-comparison
    $include_oauth = $method !== 'password';
    $include_password = $method !== 'oauth';

    $results = [];

    $abilities = check_abilities();
    $results[] = $abilities;

    $transport = check_transport();
    $results[] = $transport;

    $results[] = check_permalinks();

    $results[] = check_rest_reachable();

    $results[] = check_security_edge();

    if ($include_oauth) {
        $schema = check_schema();
        $results[] = $schema;

        // With abilities off the OAuth endpoints are not registered at all (see
        // Novamira\OAuth\boot), so the probes would only restate that failure.
        $oauth_ok = $abilities['status'] !== 'fail' && $transport['status'] !== 'fail' && $schema['status'] !== 'fail';
        $discovery_headers = [];
        $discovery = $oauth_ok
            ? check_discovery($discovery_headers)
            : skipped('discovery', __('OAuth discovery', domain: 'novamira'), $abilities, $transport, $schema);
        $results[] = $discovery;

        $results[] = $oauth_ok
            ? check_registration()
            : skipped(
                'registration',
                __('OAuth client registration', domain: 'novamira'),
                $abilities,
                $transport,
                $schema,
            );

        $results[] = $oauth_ok && $discovery['status'] === 'ok'
            ? check_bot_filter()
            : skipped(
                'bot_filter',
                __('Hosting bot filter', domain: 'novamira'),
                $abilities,
                $transport,
                $schema,
                $discovery,
            );

        $results[] = $schema['status'] !== 'fail'
            ? check_limits()
            : skipped('limits', __('Registration limits', domain: 'novamira'), $schema);

        $results[] = check_environment($discovery_headers);
    }

    if ($include_password) {
        $results[] = check_app_passwords();
    }

    return $results;
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_abilities(): array
{
    $label = __('AI Abilities', domain: 'novamira');
    if (\novamira_is_enabled()) {
        return ok(
            'abilities',
            $label,
            __('AI Abilities are turned on; the MCP endpoints are registered.', domain: 'novamira'),
        );
    }
    return fail(
        'abilities',
        $label,
        __(
            'AI Abilities are turned off, so no MCP endpoint exists and every client sees an empty or missing server.',
            domain: 'novamira',
        ),
        __(
            'Turn on AI Abilities in Step 1 of the Configuration page, then run these checks again.',
            domain: 'novamira',
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
// $action names a symptom key the UI can jump to (a report row's "Open the fix below" button),
// so a remedy that lives inside a symptom branch is one click away instead of a scavenger hunt.
// One positional parameter per key of the fixed result shape; an options array would just
// re-implement the shape without the type safety.
// @mago-expect lint:excessive-parameter-list
function result(
    string $id,
    string $status,
    string $label,
    string $message,
    string $remedy = '',
    string $action = '',
    string $copy = '',
): array {
    return [
        'id' => $id,
        'status' => $status,
        'label' => $label,
        'message' => $message,
        'remedy' => $remedy,
        'action' => $action,
        'copy' => $copy,
    ];
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function ok(string $id, string $label, string $message): array
{
    return result($id, status: 'ok', label: $label, message: $message);
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
// Mirrors result(): one positional parameter per key of the fixed result shape.
// @mago-expect lint:excessive-parameter-list
function warn(
    string $id,
    string $label,
    string $message,
    string $remedy = '',
    string $action = '',
    string $copy = '',
): array {
    return result(
        $id,
        status: 'warning',
        label: $label,
        message: $message,
        remedy: $remedy,
        action: $action,
        copy: $copy,
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
// Mirrors result(): one positional parameter per key of the fixed result shape.
// @mago-expect lint:excessive-parameter-list
function fail(
    string $id,
    string $label,
    string $message,
    string $remedy = '',
    string $action = '',
    string $copy = '',
): array {
    return result($id, status: 'fail', label: $label, message: $message, remedy: $remedy, action: $action, copy: $copy);
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function info(string $id, string $label, string $message, string $remedy = ''): array
{
    return result($id, status: 'info', label: $label, message: $message, remedy: $remedy);
}

/**
 * A skipped entry naming the failed prerequisite(s), so the report explains the gap instead of
 * silently shortening.
 *
 * @param array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} ...$failed
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function skipped(string $id, string $label, array ...$failed): array
{
    $names = [];
    foreach ($failed as $f) {
        if ($f['status'] !== 'fail') {
            continue;
        }
        $names[] = $f['label'];
    }
    return result(
        $id,
        status: 'skipped',
        label: $label,
        message: sprintf(
            /* translators: %s: comma-separated list of failed prerequisite check names */
            __('Skipped: fix "%s" first.', domain: 'novamira'),
            implode('", "', $names),
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_transport(): array
{
    $label = __('Secure transport', domain: 'novamira');
    if (\novamira_oauth_transport_allowed()) {
        return ok(
            'transport',
            $label,
            __(
                'The site is served over HTTPS (or is a local dev environment), so both connection methods are available.',
                domain: 'novamira',
            ),
        );
    }
    return fail(
        'transport',
        $label,
        __(
            'This site is served over plain HTTP on a non-local environment. Novamira does not register any OAuth endpoint here, because authorization codes and tokens would travel in cleartext.',
            domain: 'novamira',
        ),
        __(
            'Serve the site over HTTPS. Application Passwords are equally unavailable on plain HTTP.',
            domain: 'novamira',
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_permalinks(): array
{
    $label = __('Permalink structure', domain: 'novamira');
    $structure = (string) get_option('permalink_structure', default_value: '');
    if ($structure !== '') {
        return ok(
            'permalinks',
            $label,
            __(
                'Pretty permalinks are enabled; MCP and OAuth URLs use the standard /wp-json/ path form.',
                domain: 'novamira',
            ),
        );
    }
    return warn(
        'permalinks',
        $label,
        __(
            'Permalinks are set to "Plain", so MCP URLs take the index.php?rest_route= form. Connections can work this way, but strictly spec-compliant clients can fail OAuth discovery on it, and path-based cache/WAF rules on managed hosts do not recognize these URLs as API traffic.',
            domain: 'novamira',
        ),
        sprintf(
            /* translators: %s: permalink settings URL */
            __(
                'Switch to "Post name" under %s, then reconnect your AI client. A client that refreshes its token on the first 401 recovers by itself; one that does not needs a manual reconnect.',
                domain: 'novamira',
            ),
            admin_url('options-permalink.php'),
        ),
    );
}

/**
 * Names the guard that is stopping the OAuth installer from ever running.
 *
 * The installer is not tied to activation: `boot()` in includes/oauth/bootstrap.php calls
 * `Schema\maybe_install()` on every request, but only after both of its guards pass. When either one
 * returns false the installer is never reached, and no amount of reactivating changes that.
 *
 * @return string|null The guard that is blocking the installer, or null when both guards pass and
 *                     the missing tables point at the installer itself.
 */
function schema_blocked_reason(): ?string
{
    if (!\novamira_is_enabled()) {
        return __(
            'AI Abilities are turned off, or locked to a domain other than this one, so the OAuth installer never runs. See the "AI Abilities" check above.',
            domain: 'novamira',
        );
    }
    if (!\novamira_oauth_transport_allowed()) {
        return __(
            'This site is not served over HTTPS, so OAuth is switched off here and its installer never runs. See the "Secure transport" check above.',
            domain: 'novamira',
        );
    }
    return null;
}

/**
 * The installer's own definition of a complete OAuth schema, loaded on demand.
 *
 * checks.php is loaded on every request; includes/oauth/schema.php is loaded by
 * Novamira\OAuth\boot(), which returns before that when the abilities or transport gate is closed —
 * exactly the sites this page is opened on. Loading it here keeps the required tables and columns a
 * single source of truth rather than a copy that can drift from the CREATE TABLE statements.
 *
 * The file declares definitions and functions only. Its garbage-collection schedule is registered
 * by boot() through Schema\schedule_gc(), so reading a definition from here cannot leave a
 * scheduled event behind on a site whose OAuth endpoints are switched off.
 */
function require_schema_definition(): void
{
    if (function_exists('Novamira\\OAuth\\Schema\\required_columns')) {
        return;
    }
    require_once dirname(__DIR__) . '/oauth/schema.php';
}

/**
 * The storage result for an install whose six tables all exist.
 *
 * A table can exist and still be missing a column a later schema version added. Every insert that
 * names the column is then refused while the reads that do not name it keep answering, so new
 * clients cannot register while the ones already connected work — and a check that only counts
 * table names calls that healthy. A table the database would not describe is not evidence of
 * either: it is reported as unverified rather than as installed.
 *
 * @param array{missing: array<string, list<string>>, unreadable: list<string>} $report
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function schema_columns_result(string $label, string $prefix, array $report): array
{
    if ($report['unreadable'] !== []) {
        return fail(
            'schema',
            $label,
            sprintf(
                /* translators: %s: comma-separated list of database table names */
                __(
                    'The OAuth storage could not be verified: the database did not describe %s, so whether its columns are complete is unknown.',
                    domain: 'novamira',
                ),
                implode(', ', $report['unreadable']),
            ),
            __(
                'Look in the PHP error log for the database error behind it, then run these checks again. A database that answers again reports the storage as installed or names what is missing.',
                domain: 'novamira',
            ),
        );
    }
    if ($report['missing'] === []) {
        return ok('schema', $label, __('The OAuth tables are installed.', domain: 'novamira'));
    }

    $described = [];
    foreach ($report['missing'] as $suffix => $columns) {
        $described[] = $prefix . $suffix . ' (' . implode(', ', $columns) . ')';
    }
    // Which step breaks depends on the table: a clients table that cannot take the row refuses the
    // registration itself, while any other incomplete table lets registration through and fails at
    // the step that writes to it. The reason a broken connection has to be made again differs the
    // same way.
    $clients_incomplete = array_key_exists('clients', $report['missing']);
    $symptom = $clients_incomplete
        ? __(
            'No AI client can register on this site, while clients that connected before those columns were added keep working.',
            domain: 'novamira',
        )
        : __(
            'Registering an AI client still succeeds, but the sign-in step that writes to the incomplete table fails, so the connection cannot complete.',
            domain: 'novamira',
        );
    $reconnect = $clients_incomplete
        ? __(
            'a client ID handed out while the table was incomplete was never stored, so that connection cannot recover on its own.',
            domain: 'novamira',
        )
        : __('a sign-in that failed part-way does not resume on its own.', domain: 'novamira');

    return fail(
        'schema',
        $label,
        sprintf(
            /* translators: 1: list of database tables, each followed by the columns it is missing; 2: which step fails */
            __('The OAuth tables are installed but incomplete: %1$s. %2$s', domain: 'novamira'),
            implode('; ', $described),
            $symptom,
        ),
        sprintf(
            /* translators: %s: why an AI connection made while the storage was incomplete has to be made again */
            __(
                'Novamira could not add the missing column(s), which usually means the WordPress database user may not ALTER tables. 1. Send the message below to your hosting support: it contains the exact SQL that adds the missing column(s), or it asks them to grant the ALTER privilege to the WordPress database user instead. 2. Once they have done either, run these checks again: SQL they ran takes effect immediately, and with ALTER granted this check adds the columns itself. 3. Once this check passes, remove the AI connector from your AI client and add it again: %s',
                domain: 'novamira',
            ),
            $reconnect,
        ),
        copy: schema_repair_request($prefix, $report['missing']),
    );
}

/**
 * The statements that add the columns an incomplete install is missing, one per column.
 *
 * Built from the installer's own definitions — Schema\table_definitions() through
 * Schema\declared_column(), assembled by Schema\add_column_statement() — and never written out
 * here, so what an administrator hands to a host is exactly what Novamira would have run itself and
 * cannot drift from it. A column its statement does not declare gets no statement rather than a
 * guessed one.
 *
 * @param array<string, list<string>> $missing Table suffix => the required columns it does not have.
 * @return list<string>
 */
function schema_repair_statements(string $prefix, array $missing): array
{
    $definitions = \Novamira\OAuth\Schema\table_definitions($prefix);
    $statements = [];
    foreach ($missing as $suffix => $columns) {
        if (!array_key_exists($suffix, $definitions)) {
            continue;
        }
        foreach ($columns as $column) {
            $declared = \Novamira\OAuth\Schema\declared_column($definitions[$suffix], $column);
            if ($declared === null) {
                continue;
            }
            $statements[] = \Novamira\OAuth\Schema\add_column_statement($prefix . $suffix, $declared) . ';';
        }
    }

    return $statements;
}

/**
 * A message ready to send to hosting support for an incomplete OAuth storage: what is
 * wrong in plain terms, the exact statements that repair it, and the alternative of granting ALTER
 * so Novamira repairs it itself. The same pattern as the bot-filter check's support message.
 *
 * @param array<string, list<string>> $missing Table suffix => the required columns it does not have.
 */
function schema_repair_request(string $prefix, array $missing): string
{
    $paragraphs = [
        sprintf(
            /* translators: %s: site URL */
            __(
                'Hello, my WordPress site %s runs Novamira, a plugin that lets AI clients (Claude, ChatGPT and similar services) connect to the site through OAuth. Some of its database tables are missing columns, because the WordPress database user was not allowed to ALTER them, so AI clients cannot finish connecting.',
                domain: 'novamira',
            ),
            home_url(),
        ),
    ];
    $statements = schema_repair_statements($prefix, $missing);
    if ($statements === []) {
        $paragraphs[] = __(
            'Could you grant the ALTER privilege on the WordPress database to the WordPress database user? Novamira then adds the missing columns itself the next time an AI client registers or its Troubleshoot checks run. Thank you.',
            domain: 'novamira',
        );
        return implode("\n\n", $paragraphs);
    }

    $paragraphs[] = __('Could you run these statements on the WordPress database?', domain: 'novamira');
    $paragraphs[] = implode("\n", $statements);
    $paragraphs[] = __(
        'Alternatively, grant the ALTER privilege on the WordPress database to the WordPress database user: Novamira then adds the missing columns itself the next time an AI client registers or its Troubleshoot checks run. Thank you.',
        domain: 'novamira',
    );

    return implode("\n\n", $paragraphs);
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_schema(): array
{
    $label = __('OAuth storage', domain: 'novamira');
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    require_schema_definition();
    $prefix = $wpdb->prefix . 'novamira_oauth_';
    if (\Novamira\OAuth\Schema\tables_installed($prefix)) {
        // A column the installer could not add is added here if the database now allows it — this
        // page runs for administrators only, and the repair can only add columns. The report is
        // taken again afterwards so the result describes the storage as it is now, not as it was.
        $report = \Novamira\OAuth\Schema\repair_missing_columns($prefix);
        if ($report['missing'] !== []) {
            $report = \Novamira\OAuth\Schema\column_report($prefix);
        }
        return schema_columns_result($label, $prefix, $report);
    }
    $blocked_reason = schema_blocked_reason();
    if ($blocked_reason !== null) {
        return fail(
            'schema',
            $label,
            $blocked_reason,
            __('Resolve the check named above, then run these checks again.', domain: 'novamira'),
        );
    }

    return fail(
        'schema',
        $label,
        __('The OAuth tables are missing, so no OAuth client can register or authenticate.', domain: 'novamira'),
        __(
            'AI Abilities are on and this site is served over HTTPS, so the installer runs on every request and the tables should exist. Look in the PHP error log for the database error it hit, then run these checks again. Reactivating Novamira runs the same installer and will not help on its own.',
            domain: 'novamira',
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_rest_reachable(): array
{
    $label = __('Anonymous REST API', domain: 'novamira');
    $response = wp_remote_get(rest_url(), http_options());
    if (is_wp_error($response)) {
        return fail(
            'rest',
            $label,
            sprintf(
                /* translators: %s: HTTP error message */
                __('The site could not fetch its own REST index: %s', domain: 'novamira'),
                $response->get_error_message(),
            ),
            __(
                'On a public site, ask your host why the server cannot reach its own public URL (loopback requests may be blocked).',
                domain: 'novamira',
            ),
        );
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code === 401 || $code === 403) {
        return fail(
            'rest',
            $label,
            sprintf(
                /* translators: %d: HTTP status code */
                __(
                    'The REST API answers anonymous requests with HTTP %d. A security plugin is likely restricting REST access to logged-in users, which also blocks the OAuth registration step AI clients perform.',
                    domain: 'novamira',
                ),
                $code,
            ),
            __(
                'In your security plugin, allow unauthenticated access to the REST API (at minimum the novamira/v1 and mcp namespaces), then run these checks again.',
                domain: 'novamira',
            ),
        );
    }
    if ($code !== 200) {
        return warn(
            'rest',
            $label,
            sprintf(
                /* translators: %d: HTTP status code */
                __('The REST index answered HTTP %d instead of 200.', domain: 'novamira'),
                $code,
            ),
        );
    }
    return ok('rest', $label, __('The REST API answers anonymous requests.', domain: 'novamira'));
}

/**
 * Normalize a wp_remote_* headers value (array or CaseInsensitiveDictionary) into a flat
 * lowercase-keyed string map.
 *
 * @return array<string, string>
 */
function normalize_headers(mixed $raw): array
{
    if (is_object($raw) && method_exists($raw, 'getAll')) {
        // @mago-expect analysis:mixed-assignment
        $raw = $raw->getAll();
    }
    if (!is_array($raw)) {
        return [];
    }
    $headers = [];
    /** @var mixed $value */
    foreach ($raw as $name => $value) {
        if (is_array($value)) {
            $value = implode(', ', array_map(static fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $value));
        }
        if (!is_scalar($value)) {
            continue;
        }
        $headers[strtolower((string) $name)] = (string) $value;
    }
    return $headers;
}

/**
 * @param array<string, string> $headers Filled with the last discovery response's headers, for
 *                                       the environment check.
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_discovery(array &$headers): array
{
    $label = __('OAuth discovery', domain: 'novamira');
    // Single source for the URL set, shared with the request handler (endpoints/discovery.php). URLs
    // are absolute (origin + path), never home_url($path), so a subdirectory is not prepended twice.
    // The advertised protected-resource URL is required; the authorization-server document is
    // reachable through several interchangeable forms, so its group only fails when they all do.
    $probes = \Novamira\OAuth\Endpoints\Discovery\discovery_probes(home_url(), \Novamira\OAuth\resource_identifier());

    // group => whether any member answered; group => a failure to report if none did.
    $group_satisfied = [];
    $group_failure = [];

    foreach ($probes as $probe) {
        $outcome = probe_discovery_document($probe, $label, $headers);
        if ($outcome['ok']) {
            if ($probe['group'] !== '') {
                $group_satisfied[$probe['group']] = true;
            }
            continue;
        }
        if ($probe['requirement'] === 'required') {
            return $outcome['failure'] ?? discovery_generic_failure($label);
        }
        if ($probe['requirement'] === 'any') {
            $group_satisfied[$probe['group']] ??= false;
            if ($outcome['failure'] !== null) {
                $group_failure[$probe['group']] ??= $outcome['failure'];
            }
        }

        // 'optional' probes never fail discovery.
    }

    foreach ($group_satisfied as $group => $satisfied) {
        if (!$satisfied) {
            return $group_failure[$group] ?? discovery_generic_failure($label);
        }
    }

    return ok(
        'discovery',
        $label,
        __(
            'The discovery documents answer with valid JSON on the paths this site advertises. Note: this probe runs from the server itself, so a firewall that blocks only external or datacenter IPs can still affect real AI clients.',
            domain: 'novamira',
        ),
    );
}

/**
 * Fetch one discovery URL and validate it: HTTP 200, an `application/json` content type (parameters
 * such as charset ignored), a JSON body, and the identifying field carrying this site's exact
 * resource or issuer. On success the response headers are captured for the environment check.
 *
 * @param array{url: string, field: string, expected: string, requirement: string, group: string, label: string} $probe
 * @param array<string, string> $headers
 * @return array{ok: bool, failure: ?array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}}
 */
function probe_discovery_document(array $probe, string $label, array &$headers): array
{
    // Redirects are NOT followed: a redirect on these URLs is itself the finding. Hosting platforms
    // (seen on WP Engine) ship edge rules that 301 the OAuth well-known paths to the homepage;
    // following the redirect would only report "200 but not JSON", while the redirect names the
    // actual problem and its owner.
    $response = wp_remote_get($probe['url'], http_options() + ['redirection' => 0]);
    if (is_wp_error($response)) {
        return [
            'ok' => false,
            'failure' => fail(
                'discovery',
                $label,
                sprintf(
                    /* translators: 1: discovery URL, 2: HTTP error message */
                    __('Fetching %1$s failed: %2$s', domain: 'novamira'),
                    $probe['url'],
                    $response->get_error_message(),
                ),
            ),
        ];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code >= 300 && $code < 400) {
        return ['ok' => false, 'failure' => discovery_redirect_failure($probe, $label, $code, $response)];
    }
    $media_type = discovery_media_type(wp_remote_retrieve_header($response, header: 'content-type'));
    // @mago-expect analysis:mixed-assignment
    $body = json_decode(wp_remote_retrieve_body($response), associative: true);
    if ($code !== 200 || $media_type !== 'application/json' || !is_array($body)) {
        return [
            'ok' => false,
            'failure' => fail(
                'discovery',
                $label,
                sprintf(
                    /* translators: 1: discovery URL, 2: HTTP status code */
                    __(
                        '%1$s answered HTTP %2$d, or without an application/json body. AI clients cannot find the sign-in endpoints. A cache or firewall layer may be intercepting the URL.',
                        domain: 'novamira',
                    ),
                    $probe['url'],
                    $code,
                ),
                __(
                    'Exclude the /.well-known/oauth-* paths from page caching and firewall challenges, then run these checks again.',
                    domain: 'novamira',
                ),
            ),
        ];
    }
    // The document must be ours: the identifying field present AND carrying the exact resource or
    // issuer this site advertises, so a stray OAuth document from another plugin is caught.
    if (($body[$probe['field']] ?? null) !== $probe['expected']) {
        return [
            'ok' => false,
            'failure' => fail(
                'discovery',
                $label,
                sprintf(
                    /* translators: 1: discovery URL, 2: JSON field name */
                    __(
                        '%1$s returned JSON whose "%2$s" field is missing or does not match this site. Another plugin may be serving its own OAuth metadata on this path.',
                        domain: 'novamira',
                    ),
                    $probe['url'],
                    $probe['field'],
                ),
            ),
        ];
    }
    $headers = normalize_headers(wp_remote_retrieve_headers($response));
    return ['ok' => true, 'failure' => null];
}

/**
 * Report a redirect on a discovery URL, naming the layer that produced it.
 *
 * `wp_redirect()` stamps `X-Redirect-By` on everything it sends, so its presence proves the
 * redirect was decided by PHP inside this WordPress rather than by the web server, a CDN or a WAF.
 * The distinction decides who can fix it: an edge rule needs hosting support, while a redirect
 * plugin's rule is one the site owner removes themselves in wp-admin. Sending them to hosting for
 * a rule hosting cannot see costs a support round-trip and leaves the site broken, so each case
 * gets its own message and remedy.
 *
 * @param array{url: string, field: string, expected: string, requirement: string, group: string, label: string} $probe
 * @param array<array-key, mixed> $response
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function discovery_redirect_failure(array $probe, string $label, int $code, array $response): array
{
    $location = wp_remote_retrieve_header($response, header: 'location');
    $location = is_string($location) && $location !== '' ? $location : __('another URL', domain: 'novamira');
    $redirect_by = wp_remote_retrieve_header($response, header: 'x-redirect-by');
    $redirect_by = is_string($redirect_by) ? trim($redirect_by) : '';

    if ($redirect_by !== '') {
        return fail(
            'discovery',
            $label,
            sprintf(
                /* translators: 1: discovery URL, 2: HTTP status code, 3: redirect target URL, 4: value of the X-Redirect-By header */
                __(
                    '%1$s is redirected from inside WordPress (HTTP %2$d to %3$s, sent by "%4$s") instead of being answered. AI clients follow the redirect, receive a web page instead of the OAuth metadata, and sign-in fails with a registration error. A plugin on this site is claiming this exact URL before Novamira can answer it — typically a redirection, SEO or security plugin, from a rule that was created while the path still returned 404.',
                    domain: 'novamira',
                ),
                $probe['url'],
                $code,
                $location,
                $redirect_by,
            ),
            __(
                'Open the redirect rules of the redirection, SEO and security plugins on this site, delete any rule matching this path, and exclude /.well-known/ from their 404 handling and automatic redirects. Then run these checks again. Hosting cannot help with this one: a server, CDN or firewall rule would not carry an X-Redirect-By header.',
                domain: 'novamira',
            ),
        );
    }

    return fail(
        'discovery',
        $label,
        sprintf(
            /* translators: 1: discovery URL, 2: HTTP status code, 3: redirect target URL */
            __(
                '%1$s is redirected by the server (HTTP %2$d to %3$s) instead of being answered. AI clients follow the redirect, receive a web page instead of the OAuth metadata, and sign-in fails with a registration error. The redirect is decided before WordPress runs, so it is typically a hosting-level rule on the /.well-known/ paths, not something WordPress controls.',
                domain: 'novamira',
            ),
            $probe['url'],
            $code,
            $location,
        ),
        __(
            'Ask your hosting support to let this path, including any subpath, pass through to WordPress ("proxy pass as dynamic"), then run these checks again.',
            domain: 'novamira',
        ),
    );
}

/**
 * The media type of a Content-Type header, lowercased and without parameters, so
 * `application/json; charset=UTF-8` is recognized as `application/json`.
 */
function discovery_media_type(mixed $header): string
{
    if (!is_string($header)) {
        return '';
    }
    $type = strtolower(trim($header));
    $semicolon = strpos($type, needle: ';');
    return $semicolon === false ? $type : rtrim(substr($type, offset: 0, length: $semicolon));
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function discovery_generic_failure(string $label): array
{
    return fail(
        'discovery',
        $label,
        __(
            'The OAuth discovery documents could not be reached. AI clients cannot find the sign-in endpoints.',
            domain: 'novamira',
        ),
        __(
            'Exclude the /.well-known/oauth-* paths from page caching and firewall challenges, then run these checks again.',
            domain: 'novamira',
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_registration(): array
{
    $label = __('OAuth client registration', domain: 'novamira');
    // Single-use pass so the probe skips the per-IP registration limits: every self-test comes
    // from the server's own address, and counting it would let repeated diagnostics runs trip a
    // rate limit that has nothing to do with the AI clients (they use their own address buckets).
    $token = wp_generate_password(32, special_chars: false, extra_special_chars: false);
    set_transient('novamira_oauth_selftest_' . hash('sha256', $token), value: '1', expiration: MINUTE_IN_SECONDS);
    $response = wp_remote_post(rest_url('novamira/v1/oauth/register'), [
        'timeout' => HTTP_TIMEOUT,
        'sslverify' => !\novamira_likely_self_signed_https(),
        'headers' => ['Content-Type' => 'application/json', 'X-Novamira-Self-Test' => $token],
        'body' => (string) wp_json_encode([
            'client_name' => 'Novamira Self-Test',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]),
    ]);
    if (is_wp_error($response)) {
        return fail(
            'registration',
            $label,
            sprintf(
                /* translators: %s: HTTP error message */
                __('The registration test request failed: %s', domain: 'novamira'),
                $response->get_error_message(),
            ),
        );
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    // @mago-expect analysis:mixed-assignment
    $body = json_decode(wp_remote_retrieve_body($response), associative: true);
    if ($code === 201 && is_array($body) && is_string($body['client_id'] ?? null)) {
        $client_id = $body['client_id'];
        $clients = new ClientRepository();
        // 201 says the endpoint was reached, not that the client exists: a clients table that
        // refuses the insert produces a registration for a client_id the next authorization
        // request cannot resolve, and this check used to call that a success. Read it back, then
        // delete it either way so the probe leaves nothing behind even when the row is partial.
        try {
            $stored = client_is_stored($clients, $client_id);
        } finally {
            // The probe must not leave a client behind on any exit, including one this read throws:
            // a storage layer that fails mid-read is exactly when a forgotten row would stay.
            $clients->revoke($client_id);
        }
        if (!$stored) {
            return fail(
                'registration',
                $label,
                __(
                    'The registration endpoint answered HTTP 201, but the client it reported is not in the OAuth storage: the write to the clients table failed. An AI client registers, then fails to sign in because the site does not know its client ID.',
                    domain: 'novamira',
                ),
                __(
                    'See the "OAuth storage" check above for an incomplete table (its message for your host contains the exact SQL), and the PHP error log for the database error the write hit. Once storage is fixed, run these checks again, then remove the AI connector from your AI client and add it again: the client ID it was given was never stored, so the old connection cannot recover.',
                    domain: 'novamira',
                ),
            );
        }
        return ok(
            'registration',
            $label,
            __(
                'A test registration succeeded and the client was read back from storage (the test client was deleted right away). If an AI client still cannot register, the block is between that client and this site — typically a firewall or bot filter on datacenter IPs — or its attempts exhausted the per-address limits; see the registration-error symptom below.',
                domain: 'novamira',
            ),
        );
    }
    if ($code === 429) {
        return warn(
            'registration',
            $label,
            __(
                'The test registration was rate-limited for the server’s own address. This does not affect AI clients (each connects from its own address with its own budget); it usually means the self-test pass was not honored, and it clears within the hour.',
                domain: 'novamira',
            ),
            __(
                'If an AI client reports "too many attempts", clear the limits from the registration-error symptom below, then connect it once.',
                domain: 'novamira',
            ),
            action: 'registration',
        );
    }
    // A registration that could not be stored now answers 500, so the generic diagnosis below —
    // which names an intercepted request — would be a cause this check has not established. A 500
    // does not establish one either way: it can come from the registration code or from a layer
    // answering in its place, so both are named and neither is asserted.
    if ($code === 500) {
        return fail(
            'registration',
            $label,
            __(
                'The registration endpoint answered HTTP 500. Either the registration ran and could not store the client, or a security plugin, firewall or the server answered for it — this check cannot tell which from the status alone.',
                domain: 'novamira',
            ),
            __(
                'Look at the "OAuth storage" check above: an incomplete table there is the cause (its message for your host contains the exact SQL), and the PHP error log carries the database error behind it. If storage is reported as installed, allow anonymous POST requests to /wp-json/novamira/v1/oauth/register. Once storage is fixed and this check passes, remove the AI connector from your AI client and add it again, so it registers afresh instead of reusing a registration that failed.',
                domain: 'novamira',
            ),
        );
    }

    return fail(
        'registration',
        $label,
        sprintf(
            /* translators: %d: HTTP status code */
            __(
                'The registration endpoint answered HTTP %d instead of 201. A security plugin or firewall is likely intercepting POST requests to the REST API.',
                domain: 'novamira',
            ),
            $code,
        ),
        __(
            'Allow anonymous POST requests to /wp-json/novamira/v1/oauth/register, then run these checks again.',
            domain: 'novamira',
        ),
    );
}

/**
 * How many times the registration probe looks for the client it has just registered, and how long
 * it waits between attempts.
 *
 * The registration happened in a separate HTTP request, which wrote; this request reads. Where
 * reads are answered by a replica, that replica can be a moment behind the write, and one missed
 * read is not evidence that the write failed — telling a working site that its storage is broken is
 * the same class of defect as the false success this check exists to catch. A healthy site pays for
 * the first read only.
 */
const STORE_READ_BACK_ATTEMPTS = 3;

const STORE_READ_BACK_WAIT = 250_000;

/** Whether the registered client can be read back from the store, allowing for a lagging replica. */
function client_is_stored(ClientRepository $clients, string $client_id): bool
{
    for ($attempt = 1; $attempt <= STORE_READ_BACK_ATTEMPTS; $attempt++) {
        if ($clients->getClientEntity($client_id) !== null) {
            return true;
        }
        if ($attempt < STORE_READ_BACK_ATTEMPTS) {
            usleep(STORE_READ_BACK_WAIT);
        }
    }

    return false;
}

/**
 * The HTTP-library user agents common AI clients connect with (Claude.ai's OAuth client sends
 * python-httpx). Hosts with edge bot protection often reject these signatures while letting
 * browser and WordPress user agents through, which strands connector sign-in before it reaches
 * PHP — the server-side checks all pass while every real client fails.
 *
 * @return list<string>
 */
function bot_filter_user_agents(): array
{
    /** @var mixed $agents */
    $agents = apply_filters('novamira_troubleshoot_bot_filter_user_agents', [
        'python-httpx/0.28.1',
        'python-requests/2.32.3',
        'node',
        'Go-http-client/2.0',
    ]);
    if (!is_array($agents)) {
        return [];
    }
    $clean = [];
    /** @var mixed $agent */
    foreach ($agents as $agent) {
        if (!is_string($agent) || $agent === '') {
            continue;
        }
        $clean[] = $agent;
    }
    return $clean;
}

/**
 * Names of the security / edge layers detected in front of the site, from response headers and the
 * active-plugin list. Presence only — this does not prove any of them is blocking.
 *
 * @param array<string, string> $headers        Lower-cased response headers from a self-probe.
 * @param list<string>          $active_plugins  Plugin basenames, e.g. "wordfence/wordfence.php".
 * @return list<string>
 */
function detect_security_edge(array $headers, array $active_plugins): array
{
    $found = [];

    $server = strtolower($headers['server'] ?? '');
    if (array_key_exists('cf-ray', $headers) || str_contains($server, 'cloudflare')) {
        $found[] = 'Cloudflare';
    }
    if (array_key_exists('x-sucuri-id', $headers) || array_key_exists('x-sucuri-cache', $headers)) {
        $found[] = 'Sucuri';
    }
    foreach ($headers as $name => $_value) {
        if (!str_starts_with($name, 'x-wpe-') && !str_starts_with($name, 'x-wpengine')) {
            continue;
        }
        $found[] = 'WP Engine';
        break;
    }
    if (str_contains($server, 'litespeed')) {
        $found[] = 'LiteSpeed';
    }

    $plugin_labels = [
        'wordfence/' => 'Wordfence',
        'better-wp-security/' => 'Solid Security',
        'ithemes-security-pro/' => 'Solid Security',
        'sucuri-scanner/' => 'Sucuri Security',
        'all-in-one-wp-security-and-firewall/' => 'All-In-One Security',
        'wp-cerber/' => 'WP Cerber',
        'wp-simple-firewall/' => 'Shield Security',
        'malcare-security/' => 'MalCare',
    ];
    foreach ($active_plugins as $plugin) {
        foreach ($plugin_labels as $slug => $label) {
            if (!str_starts_with($plugin, $slug)) {
                continue;
            }
            $found[] = $label;
        }
    }

    return array_values(array_unique($found));
}

/**
 * The security / edge layers detected in front of this site right now, cached for the duration of
 * the request so the diagnostics run and the support report share a single self-probe. Combines the
 * response headers of a self-request with the active-plugin list.
 *
 * @return list<string>
 */
function current_security_edge_layers(): array
{
    /** @var list<string>|null $cached */
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $headers = [];
    $response = wp_remote_get(home_url('/'), http_options() + ['redirection' => 0]);
    if (!is_wp_error($response)) {
        $headers = normalize_headers(wp_remote_retrieve_headers($response));
    }

    // @mago-expect analysis:mixed-assignment
    $raw_active = \get_option('active_plugins');
    $active = is_array($raw_active) ? $raw_active : [];
    $plugins = [];
    /** @var mixed $plugin */
    foreach ($active as $plugin) {
        if (!is_string($plugin)) {
            continue;
        }
        $plugins[] = $plugin;
    }

    // @mago-expect lint:inline-variable-return
    $cached = detect_security_edge($headers, $plugins);
    return $cached;
}

/**
 * Report which security / edge layers sit in front of the site. Presence only: this never asserts a
 * layer is blocking (the self-probe runs from the server's own IP, so an external block can be
 * invisible). Runs for both connection methods, since a CDN/WAF blocks App Password MCP traffic too.
 *
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_security_edge(): array
{
    $label = __('Security & edge layers', domain: 'novamira');

    $found = current_security_edge_layers();
    if ($found === []) {
        return ok(
            'security_edge',
            $label,
            __('No CDN, WAF, or security plugin was detected in front of the site.', domain: 'novamira'),
        );
    }

    return info(
        'security_edge',
        $label,
        sprintf(
            /* translators: %s: comma-separated list of detected security/edge layer names */
            __(
                'Detected in front of your site: %s. These protect your site — keep them. Our checks pass from here, but such layers can still filter AI clients from the outside. If a client cannot connect, ask for the MCP paths to be allowed through — do not turn the security off.',
                domain: 'novamira',
            ),
            implode(', ', $found),
        ),
        __(
            'Allow these paths through the CDN/WAF/security layer, by path (not by User-Agent): /wp-json/mcp/novamira and /.well-known/oauth-* . Keep every other protection on.',
            domain: 'novamira',
        ),
    );
}

/**
 * Probe the public discovery URL with the user agents above. The discovery check has already
 * proven the same URL answers the server's default user agent, so any signature rejected here
 * is the edge layer discriminating by client fingerprint — a warning, not a failure: the site
 * itself is healthy and the fix belongs to the host.
 *
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_bot_filter(): array
{
    $label = __('Hosting bot filter', domain: 'novamira');
    $url = home_url('/.well-known/oauth-authorization-server');
    $blocked = [];
    foreach (bot_filter_user_agents() as $agent) {
        $response = wp_remote_get($url, array_merge(http_options(), ['user-agent' => $agent]));
        if (is_wp_error($response)) {
            $blocked[$agent] = $response->get_error_message();
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        // 403 is the plain rejection, 503 the challenge page some bot filters serve instead.
        if ($code === 403 || $code === 503) {
            $blocked[$agent] = sprintf('HTTP %d', $code);
        }
    }
    if ($blocked === []) {
        return ok(
            'bot_filter',
            $label,
            __(
                'The tested non-browser user agents all reach the discovery URL. Note: this probe runs from the server\'s own address, so a filter acting only on external or datacenter IPs can still affect real AI clients.',
                domain: 'novamira',
            ),
        );
    }
    $list = [];
    foreach ($blocked as $agent => $reason) {
        $list[] = sprintf('"%s" (%s)', $agent, $reason);
    }
    $first_agent = array_key_first($blocked);
    return warn(
        'bot_filter',
        $label,
        sprintf(
            /* translators: %s: comma-separated list of blocked user agents with the response each received */
            __(
                'A security layer in front of this site rejects some non-browser user agents on the OAuth discovery URL: %s. AI clients connect with signatures like these, so their sign-in requests are likely blocked before they reach WordPress, even though every server-side check passes.',
                domain: 'novamira',
            ),
            implode(', ', $list),
        ),
        __(
            'Ask your hosting support to allow this traffic for your site. The message below is ready to copy into a support ticket.',
            domain: 'novamira',
        ),
        copy: sprintf(
            /* translators: 1: site URL, 2: probed discovery URL, 3: blocked user agent, 4: response it received */
            __(
                'Hello, my WordPress site %1$s runs Novamira, a plugin that lets AI clients (Claude, ChatGPT and similar services) connect to the site through OAuth. Your edge layer is rejecting those clients\' requests before they reach WordPress, so the connection cannot complete. I can reproduce it: a request to %2$s with the user agent "%3$s" is rejected (%4$s), while the same request with a regular user agent goes through. Could you allow this traffic for my site? The affected paths are /.well-known/oauth-* and /wp-json/. Thank you.',
                domain: 'novamira',
            ),
            home_url(),
            $url,
            $first_agent,
            $blocked[$first_agent],
        ),
    );
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_limits(): array
{
    $label = __('Registration limits', domain: 'novamira');
    // @mago-expect lint:no-global
    global $wpdb;
    /** @var \wpdb $wpdb */
    $active = \Novamira\OAuth\ClientValidation\active_client_count();
    $cap = \Novamira\OAuth\ClientValidation\max_clients_per_site();
    $pending_cutoff = gmdate('Y-m-d H:i:s', time() - \Novamira\OAuth\ClientValidation\STALE_UNUSED_CLIENT_TTL);
    // @mago-expect analysis:possibly-invalid-argument
    // @mago-expect analysis:possibly-invalid-argument
    $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}novamira_oauth_clients
             WHERE last_used_at IS NULL AND admin_created = 0 AND created_at >= %s", $pending_cutoff));
    if ($active >= $cap) {
        return fail(
            'limits',
            $label,
            sprintf(
                /* translators: 1: active connection count, 2: connection cap */
                __(
                    'All %2$d connection slots are in use (%1$d active connections). New registrations are refused until one expires or is revoked.',
                    domain: 'novamira',
                ),
                $active,
                $cap,
            ),
            __(
                'Revoke unused connections from the Manage Connections page: each one frees its slot right away. A site that genuinely runs more simultaneous connections can raise the cap with the novamira_oauth_max_clients filter.',
                domain: 'novamira',
            ),
        );
    }
    if ($pending >= 5) {
        return warn(
            'limits',
            $label,
            sprintf(
                /* translators: %d: count of pending registrations from the last 24 hours */
                __(
                    '%d registrations from the last 24 hours never completed sign-in. Each failed connection attempt leaves one behind, and enough of them trip the per-address limits, so an AI client that keeps retrying can start receiving "too many attempts" errors even after the original problem is fixed. They are cleaned up automatically after 24 hours.',
                    domain: 'novamira',
                ),
                $pending,
            ),
            __(
                'Clear them from the registration-error symptom below, then connect the client once.',
                domain: 'novamira',
            ),
            action: 'registration',
        );
    }
    return ok(
        'limits',
        $label,
        sprintf(
            /* translators: 1: active connection count, 2: connection cap, 3: pending registration count */
            __(
                '%1$d of %2$d connection slots in use, %3$d pending registrations in the last 24 hours.',
                domain: 'novamira',
            ),
            $active,
            $cap,
            $pending,
        ),
    );
}

/**
 * Informational only: surface the serving stack seen on the discovery response so the
 * "server healthy but AI client blocked upstream" conversation has facts to start from.
 *
 * @param array<string, string> $headers
 * @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}
 */
function check_environment(array $headers): array
{
    $label = __('Serving stack', domain: 'novamira');
    if ($headers === []) {
        return result(
            'environment',
            status: 'skipped',
            label: $label,
            message: __('No discovery response headers available (see the discovery check).', domain: 'novamira'),
        );
    }
    $seen = [];
    foreach (['server', 'x-powered-by', 'cf-ray', 'x-cache', 'x-cacheable'] as $name) {
        if (($headers[$name] ?? '') === '') {
            continue;
        }
        $seen[] = $name . ': ' . $headers[$name];
    }
    $stack = $seen === [] ? __('No identifying headers observed.', domain: 'novamira') : implode(' · ', $seen);
    $note = '';
    if (($headers['cf-ray'] ?? '') !== '' || stripos($headers['server'] ?? '', needle: 'cloudflare') !== false) {
        $note =
            ' '
            . __(
                'Cloudflare is in front of this site: its bot protection can challenge AI clients connecting from datacenter addresses even though this server-side probe passes. If registration keeps failing for clients only, review the Cloudflare security settings for this zone.',
                domain: 'novamira',
            );
    }
    return ok('environment', $label, $stack . $note);
}

/**
 * A plain-text diagnostic report for support: site context, detected layers, and every check with
 * its status. No secrets.
 *
 * @param array{site_url: string, novamira_version: string, wp_version: string, php_version: string, method: string, hosting: string} $meta
 * @param list<string>                                                                                                $detected_layers
 * @param list<array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string}> $checks
 */
function build_support_report(array $meta, array $detected_layers, array $checks): string
{
    $lines = [];
    $lines[] = 'Novamira connection diagnostic';
    $lines[] = 'Site: ' . $meta['site_url'];
    if ($meta['hosting'] !== '') {
        $lines[] = 'Hosting detected: ' . $meta['hosting'];
    }
    $lines[] =
        'Novamira: '
        . $meta['novamira_version']
        . ' | WordPress: '
        . $meta['wp_version']
        . ' | PHP: '
        . $meta['php_version'];
    $lines[] = 'Connection method: ' . ($meta['method'] === '' ? 'all' : $meta['method']);
    $lines[] = 'Security/edge detected: ' . ($detected_layers === [] ? 'none' : implode(', ', $detected_layers));
    $lines[] = '';
    $lines[] = 'Checks:';

    // Every check, not just the failing ones: the full battery makes the report self-describing, and
    // since different Novamira versions ship different checks, the list itself shows what this
    // version verified.
    if ($checks === []) {
        $lines[] = '- none';
    }
    foreach ($checks as $check) {
        $lines[] = '- [' . strtoupper($check['status']) . '] ' . $check['label'] . ': ' . $check['message'];
    }

    return implode("\n", $lines);
}

/** @return array{id: string, status: string, label: string, message: string, remedy: string, action: string, copy: string} */
function check_app_passwords(): array
{
    $label = __('Application Passwords', domain: 'novamira');
    $status = \novamira_app_passwords_status();
    if ($status['available']) {
        return ok(
            'app_passwords',
            $label,
            __('Application Passwords are available for the password connection method.', domain: 'novamira'),
        );
    }
    if ($status['reason'] === 'filtered') {
        return fail('app_passwords', $label, $status['message']);
    }
    return warn('app_passwords', $label, $status['message']);
}
