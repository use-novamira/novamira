<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\CachePurge;

use Novamira\OAuth\Endpoints\Discovery;

if (!defined('ABSPATH')) {
    exit();
}

require_once __DIR__ . '/oauth/endpoints/discovery.php';

/**
 * The options whose value decides whether the OAuth discovery documents exist. A page cache that
 * stored the answer from before the change keeps serving it, so every effective write to one of
 * these must invalidate the discovery URLs.
 *
 * @var list<string>
 */
const WATCHED_OPTIONS = ['novamira_ai_abilities_enabled', 'novamira_ai_abilities_domain'];

function register(): void
{
    // Core fires these three only when the database actually changed: `update_option()` returns
    // early when the new value matches the stored one, `add_option` only on creation and
    // `deleted_option` only after a row was removed. Hooking the option rather than the admin
    // form means a write from anywhere (REST, WP-CLI, another plugin) invalidates the cache too.
    add_action('added_option', callback: __NAMESPACE__ . '\\maybe_purge_for_option');
    add_action('updated_option', callback: __NAMESPACE__ . '\\maybe_purge_for_option');
    add_action('deleted_option', callback: __NAMESPACE__ . '\\maybe_purge_for_option');

    // Runs before the discovery handler (init, priority 1) so the response it produces — and the
    // 404 WordPress produces when the handler is not registered — are both kept out of page caches.
    add_action('init', callback: __NAMESPACE__ . '\\maybe_mark_uncacheable', priority: 0);
}

function maybe_purge_for_option(string $option): void
{
    if (!in_array($option, WATCHED_OPTIONS, strict: true)) {
        return;
    }

    schedule_purge();
}

/**
 * Purge now, or as soon as the cache plugins have registered their hooks.
 *
 * Cache plugins publish their purge API while their plugin file loads, which is before
 * `plugins_loaded` fires; a write that happens earlier than that would find nothing listening.
 * Later writes — admin, REST, WP-CLI — purge immediately.
 *
 * `did_action()` on the completion hook keeps several writes in the same request (disabling stores
 * `enabled` and deletes `domain`) to a single purge, without any state of our own to reset.
 */
function schedule_purge(): void
{
    if (did_action('novamira_purged_discovery_urls') > 0) {
        return;
    }

    $callback = __NAMESPACE__ . '\\purge_discovery_urls';
    if (did_action('plugins_loaded') === 0) {
        if (has_action('plugins_loaded', $callback) === false) {
            add_action('plugins_loaded', callback: $callback, priority: PHP_INT_MAX);
        }

        return;
    }

    purge_discovery_urls();
}

function purge_discovery_urls(): void
{
    if (did_action('novamira_purged_discovery_urls') > 0) {
        return;
    }

    $urls = discovery_urls();
    purge_urls($urls);

    // Fired even when no known cache answered, so a cache this list does not cover can invalidate
    // the same URLs without the plugin having to know about it.
    do_action('novamira_purged_discovery_urls', $urls);
}

/**
 * Every absolute discovery URL this install can be asked for, taken from the same table the handler
 * and the troubleshooter use, so a URL can never be served on one side and left cached on the other.
 *
 * @return list<string>
 */
function discovery_urls(): array
{
    $probes = Discovery\discovery_probes(home_url(), \Novamira\OAuth\resource_identifier());

    return array_values(array_unique(array_column($probes, column_key: 'url')));
}

/**
 * Invalidate the given URLs on every page cache that exposes a per-URL purge, and on no other.
 * A site-wide flush is never requested: these are a handful of URLs, and emptying the whole cache
 * of a production site because an option changed is not ours to do.
 *
 * Each integration is gated on the presence of its own published API, so an absent cache plugin
 * costs one existence check and adds no dependency.
 *
 * @param list<string> $urls
 * @return list<string> The caches that were asked to purge, for logging and tests.
 */
function purge_urls(array $urls): array
{
    if ($urls === []) {
        return [];
    }

    return array_merge(purge_via_actions($urls), purge_via_functions($urls), purge_via_objects($urls));
}

/**
 * Caches that take a URL on an action of their own: LiteSpeed Cache (`litespeed_purge_url`,
 * src/api.cls.php), Cache Enabler and WP Fastest Cache.
 *
 * @param list<string> $urls
 * @return list<string>
 */
function purge_via_actions(array $urls): array
{
    $caches = [
        'litespeed_purge_url' => 'litespeed-cache',
        'cache_enabler_clear_page_cache_by_url' => 'cache-enabler',
        'wpfc_clear_cache_by_url' => 'wp-fastest-cache',
    ];

    // Keeps LiteSpeed from queueing a green "Purge url ..." admin notice per URL for a purge the
    // user never asked for.
    if (has_action('litespeed_purge_url') !== false && !defined('LITESPEED_PURGE_SILENT')) {
        define(constant_name: 'LITESPEED_PURGE_SILENT', value: true);
    }

    $purged = [];
    foreach ($caches as $hook => $name) {
        if (has_action($hook) === false) {
            continue;
        }
        foreach ($urls as $url) {
            do_action($hook, $url);
        }
        $purged[] = $name;
    }

    return $purged;
}

/**
 * Caches that publish a function: WP Rocket (`rocket_clean_files()`, which takes the whole list),
 * W3 Total Cache and WP Super Cache (one URL per call; WP Super Cache declines URLs carrying a
 * query string, which discovery URLs never do).
 *
 * @param list<string> $urls
 * @return list<string>
 */
function purge_via_functions(array $urls): array
{
    $purged = [];

    if (purge_via_function('rocket_clean_files', argument: $urls)) {
        $purged[] = 'wp-rocket';
    }

    $caches = ['w3tc_flush_url' => 'w3-total-cache', 'wpsc_delete_url_cache' => 'wp-super-cache'];
    foreach ($caches as $function => $name) {
        $done = false;
        foreach ($urls as $url) {
            $done = purge_via_function($function, argument: $url) || $done;
        }
        if ($done) {
            $purged[] = $name;
        }
    }

    return $purged;
}

/**
 * Caches reached through an object: Nginx Helper's purger, published as a global, and SG
 * Optimizer's Supercacher. `false` keeps the SG purge to the exact URL instead of its subpaths.
 *
 * @param list<string> $urls
 * @return list<string>
 */
function purge_via_objects(array $urls): array
{
    $supercacher = 'SiteGround_Optimizer\\Supercacher\\Supercacher';
    $purged = [];
    $nginx = false;
    $siteground = false;

    foreach ($urls as $url) {
        $nginx = purge_via_global_object('nginx_purger', method: 'purge_url', url: $url) || $nginx;
        $siteground =
            purge_via_static_method($supercacher, method: 'purge_cache_request', url: $url, recursive: false)
            || $siteground;
    }

    if ($nginx) {
        $purged[] = 'nginx-helper';
    }
    if ($siteground) {
        $purged[] = 'sg-cachepress';
    }

    return $purged;
}

/**
 * @param string|list<string> $argument
 */
function purge_via_function(string $function, $argument): bool
{
    if (!function_exists($function)) {
        return false;
    }

    $function($argument);

    return true;
}

function purge_via_global_object(string $global, string $method, string $url): bool
{
    // @mago-expect lint:no-global -- the purger object is how Nginx Helper publishes its purge API.
    // @mago-expect analysis:mixed-assignment -- a global set by another plugin; guarded below.
    $object = $GLOBALS[$global] ?? null;
    if (!is_object($object) || !method_exists($object, $method)) {
        return false;
    }

    // @mago-expect analysis:string-member-selector -- guarded by method_exists() above.
    $object->{$method}($url);

    return true;
}

function purge_via_static_method(string $class, string $method, string $url, bool $recursive): bool
{
    if (!method_exists($class, $method)) {
        return false;
    }

    $callable = [$class, $method];
    $callable($url, $recursive);

    return true;
}

/**
 * Keep the OAuth discovery paths out of every page cache, whether or not this install currently
 * answers on them.
 *
 * The failure this prevents: while AI abilities are off, the discovery paths 404. A page cache
 * stores that 404, the user enables the abilities, and the cache keeps serving the stored 404 to
 * the AI client, which reports the site as not supporting OAuth. Purging on the option change
 * repairs it afterwards; refusing to cache these paths means there is nothing to repair, and it
 * also covers caches that expose no per-URL purge at all.
 *
 * Only these paths are affected, and nothing about consent, authentication or the abilities'
 * default state changes.
 */
function maybe_mark_uncacheable(): void
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', component: PHP_URL_PATH);
    // Cheap reject first: every discovery path carries this segment, and the check runs on every
    // request, so the URL table below is only built for requests that could be discovery.
    if (!is_string($path) || !str_contains($path, '/.well-known/')) {
        return;
    }
    if (!is_discovery_path($path)) {
        return;
    }

    // Honoured by LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache,
    // Cache Enabler and Breeze, among others.
    if (!defined('DONOTCACHEPAGE')) {
        define(constant_name: 'DONOTCACHEPAGE', value: true);
    }

    // LiteSpeed reads the constant too, but its own switch is explicit and carries the reason into
    // the debug log.
    $reason = 'Novamira OAuth discovery';
    do_action('litespeed_control_set_nocache', $reason);

    if (!headers_sent()) {
        header('Cache-Control: no-store, max-age=0');
    }
}

function is_discovery_path(string $path): bool
{
    $paths = Discovery\discovery_paths(home_url(), \Novamira\OAuth\resource_identifier());

    return (
        in_array($path, $paths['protected_resource'], strict: true)
        || in_array($path, $paths['authorization_server'], strict: true)
    );
}
