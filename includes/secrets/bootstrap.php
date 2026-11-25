<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Keep the site's secrets out of what reaches an AI agent.
 *
 * Every ability result that leaves the site towards an agent (MCP tool calls, direct Abilities REST runs and
 * Novamira Chat) is passed through redact(): wp-config keys and salts, the database password, Novamira's
 * OAuth keys, password hashes and common API key shapes become "[redacted]"; passwords set in clear while it ran become
 * "[redacted] (a password set in this call)". Values in .env files read with
 * read-file are redacted whole. This guards against good-faith leaks, not code that sets out to get around it.
 */

namespace Novamira\Secrets;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/redact.php';

add_filter('novamira_mcp_adapter_tool_call_result', __NAMESPACE__ . '\\redact_tool_result', PHP_INT_MAX);
add_filter('rest_post_dispatch', __NAMESPACE__ . '\\redact_ability_run_response', PHP_INT_MAX, accepted_args: 3);
add_action('wp_set_password', __NAMESPACE__ . '\\remember_password');
add_action('user_register', __NAMESPACE__ . '\\remember_new_user_password', accepted_args: 2);
add_action('profile_update', __NAMESPACE__ . '\\remember_updated_user_password', accepted_args: 3);

/**
 * A password set in clear while the AI works, by wp_set_password(), a reset or wp_insert_user(): the AI reads it as
 * hidden, so a new user's password does not travel to the model.
 */
function remember_password(mixed $password): void
{
    if (is_string($password)) {
        set_passwords($password);
    }
}

function remember_new_user_password(mixed $user_id, mixed $userdata = null): void
{
    if (is_array($userdata)) {
        remember_password($userdata['user_pass'] ?? null);
    }
}

function remember_updated_user_password(mixed $user_id, mixed $old_user_data = null, mixed $userdata = null): void
{
    remember_new_user_password($user_id, $userdata);
}

/** MCP tool results, including results of abilities run through mcp-adapter-execute-ability. */
function redact_tool_result(mixed $result): mixed
{
    if (is_wp_error($result)) {
        // Error messages can quote what failed, a secret included.
        $redacted = new \WP_Error();
        /** @var list<int|string> $codes */
        $codes = $result->get_error_codes();
        foreach ($codes as $code) {
            /** @var list<string> $messages */
            $messages = $result->get_error_messages($code);
            foreach ($messages as $message) {
                $redacted->add($code, (string) redact($message), redact($result->get_error_data($code)));
            }
        }
        return $redacted;
    }
    return redact($result);
}

/**
 * Direct runs of abilities over REST: the Abilities API (/wp-abilities/v1/abilities/{name}/run) and Novamira's runner
 * (/novamira/v1/abilities/{name}/run), the one Novamira CLI uses.
 *
 * @param mixed $response
 * @param mixed $server
 * @param mixed $request
 * @return mixed
 */
function redact_ability_run_response($response, $server, $request)
{
    if (!$response instanceof WP_REST_Response || !$request instanceof WP_REST_Request) {
        return $response;
    }
    // WordPress matches routes ignoring case, so this does too.
    if (preg_match('#^/(?:wp-abilities/|novamira/v1/abilities/).*/run$#i', $request->get_route()) !== 1) {
        return $response;
    }
    $response->set_data(redact($response->get_data()));
    return $response;
}
