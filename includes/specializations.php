<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Which third-party plugins and themes Novamira Pro specializes in.
 *
 * The list is generated from the Pro manifest and published with every Pro
 * release. This plugin deliberately keeps no copy of its own: a copy shipped in
 * the zip would be a second list to keep in sync, which is the drift this whole
 * mechanism exists to remove. A cron fetches it — once shortly after activation,
 * then every three days — and until it lands the upsell simply stays generic.
 */

if (!defined('ABSPATH')) {
    exit();
}

const NOVAMIRA_SPECIALIZATIONS_URL = 'https://license.dynamic.ooo/changelogs/novamira-pro/specializations.json';

const NOVAMIRA_SPECIALIZATIONS_OPTION = 'novamira_specializations';

/**
 * The document shape this plugin knows how to read. A published file announcing
 * anything else is refused whole rather than read with today's assumptions: an
 * install that is years old keeps its last good copy instead of misreading a
 * format written after it shipped.
 */
const NOVAMIRA_SPECIALIZATIONS_SCHEMA = 1;

const NOVAMIRA_SPECIALIZATIONS_HOOK = 'novamira_specializations_refresh';

const NOVAMIRA_SPECIALIZATIONS_SCHEDULE = 'novamira_three_days';

/**
 * Refusal ceiling for the fetched body. The published file is a few KB; anything
 * this large is a wrong URL or a captive portal, not our list.
 */
const NOVAMIRA_SPECIALIZATIONS_MAX_BYTES = 262_144;

/**
 * The specializations, as last fetched. Empty until the first refresh lands, and
 * empty on a site with no outbound network: callers treat that as "recognized
 * nothing", which is the same path as a site running none of them.
 *
 * @return list<array{name: string, label: string, plugins: list<string>, themes: list<string>}>
 */
function novamira_specializations(): array
{
    /** @var list<array{name: string, label: string, plugins: list<string>, themes: list<string>}>|null $cached */
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    /** @var mixed $stored */
    $stored = get_option(NOVAMIRA_SPECIALIZATIONS_OPTION, default_value: null);

    return $cached = is_array($stored) ? novamira_specializations_clean($stored) : [];
}

/**
 * Keep only entries that are shaped the way we expect, and drop the rest. The
 * document is fetched over the network, so nothing in it is trusted: every value
 * is checked before it is used, and a malformed entry costs that entry, not the
 * list.
 *
 * @param array<array-key, mixed> $document
 * @return list<array{name: string, label: string, plugins: list<string>, themes: list<string>}>
 */
function novamira_specializations_clean(array $document): array
{
    if (($document['schema_version'] ?? null) !== NOVAMIRA_SPECIALIZATIONS_SCHEMA) {
        return [];
    }

    /** @var mixed $entries */
    $entries = $document['specializations'] ?? null;
    if (!is_array($entries)) {
        return [];
    }

    $clean = [];
    /** @var mixed $entry */
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        /** @var mixed $name */
        $name = $entry['name'] ?? null;
        /** @var mixed $label */
        $label = $entry['label'] ?? null;
        if (!is_string($name) || !is_string($label)) {
            continue;
        }
        if (trim($name) === '' || trim($label) === '') {
            continue;
        }

        $clean[] = [
            'name' => $name,
            'label' => $label,
            'plugins' => novamira_specializations_slugs($entry['plugins'] ?? null),
            'themes' => novamira_specializations_slugs($entry['themes'] ?? null),
        ];
    }
    return $clean;
}

/**
 * A declared slug list, reduced to the folder names that could plausibly be one.
 *
 * @return list<string>
 */
function novamira_specializations_slugs(mixed $slugs): array
{
    if (!is_array($slugs)) {
        return [];
    }

    $clean = [];
    /** @var mixed $slug */
    foreach ($slugs as $slug) {
        if (!is_string($slug) || preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $slug) !== 1) {
            continue;
        }
        $clean[] = $slug;
    }
    return $clean;
}

/**
 * Folder names of everything active on this site: plugin folders, the active
 * theme, and its parent when a child theme is in use.
 *
 * `active_plugins` is an autoloaded option, so this reads memory rather than
 * disk — unlike get_plugins(), which parses every plugin header.
 *
 * @return array<string, true>
 */
function novamira_active_folders(): array
{
    /** @var array<string, true>|null $folders */
    static $folders = null;
    if ($folders !== null) {
        return $folders;
    }

    $folders = [];
    /** @var mixed $active */
    $active = get_option('active_plugins', default_value: []);
    $files = is_array($active) ? $active : [];

    // Network-activated plugins never appear in `active_plugins`; on multisite
    // they live in a site-wide option keyed by the same plugin file.
    if (is_multisite()) {
        /** @var mixed $network */
        $network = get_site_option('active_sitewide_plugins', default_value: []);
        if (is_array($network)) {
            $files = [...$files, ...array_keys($network)];
        }
    }

    /** @var mixed $file */
    foreach ($files as $file) {
        if (!is_string($file) || $file === '') {
            continue;
        }
        $folders[dirname($file)] = true;
    }

    $theme = wp_get_theme();
    $folders[$theme->get_template()] = true;
    $folders[$theme->get_stylesheet()] = true;

    return $folders;
}

/**
 * Whether anything this specialization covers is active on the site.
 *
 * @param array{name: string, label: string, plugins: list<string>, themes: list<string>} $specialization
 * @param array<string, true> $folders
 */
function novamira_specialization_is_active(array $specialization, array $folders): bool
{
    foreach ([...$specialization['plugins'], ...$specialization['themes']] as $slug) {
        if (($folders[$slug] ?? false) === true) {
            return true;
        }
    }
    return false;
}

/**
 * Fetch the published list and keep it. On any failure the stored copy is left
 * alone: a list one release old beats no list at all.
 */
function novamira_specializations_refresh(): void
{
    // The list exists to name what Pro would specialize in on a site without
    // it. Where Pro is running there is no upsell to personalize, so fetching
    // it would be traffic from every paying install for something nobody reads.
    if (function_exists('novamira_pro_is_active') && novamira_pro_is_active()) {
        return;
    }

    $response = wp_remote_get(NOVAMIRA_SPECIALIZATIONS_URL, [
        'timeout' => 10,
        'redirection' => 2,
        'user-agent' => 'Novamira/' . NOVAMIRA_VERSION . '; ' . home_url(),
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return;
    }

    $body = wp_remote_retrieve_body($response);
    if ($body === '' || strlen($body) > NOVAMIRA_SPECIALIZATIONS_MAX_BYTES) {
        return;
    }

    /** @var mixed $decoded */
    $decoded = json_decode($body, associative: true);
    if (!is_array($decoded) || novamira_specializations_clean($decoded) === []) {
        return;
    }

    update_option(NOVAMIRA_SPECIALIZATIONS_OPTION, $decoded, autoload: false);
}

add_action(NOVAMIRA_SPECIALIZATIONS_HOOK, callback: 'novamira_specializations_refresh');

/**
 * The published file changes only when a Pro release does, so three days is
 * often enough to stay current and rare enough to be invisible to the server.
 *
 * @param mixed $schedules
 * @return mixed
 */
function novamira_specializations_cron_schedule(mixed $schedules): mixed
{
    if (!is_array($schedules)) {
        return $schedules;
    }
    $schedules[NOVAMIRA_SPECIALIZATIONS_SCHEDULE] = [
        'interval' => 3 * DAY_IN_SECONDS,
        'display' => __('Every three days', domain: 'novamira'),
    ];
    return $schedules;
}

add_filter('cron_schedules', callback: 'novamira_specializations_cron_schedule');

/**
 * Scheduled once and left alone. Sites activate at their own times, so the
 * refreshes spread themselves; re-scheduling on every update would be what
 * bunched them together.
 *
 * The first run is due immediately so a freshly activated site has the list
 * within minutes — the welcome notice is shown then, and it is the moment the
 * personalized copy matters most.
 *
 * Pro keeps this plugin active, so a Pro site would otherwise carry a recurring
 * event that only ever returns early. It is removed there instead, and comes
 * back — due immediately — the first time the admin loads without Pro.
 */
function novamira_schedule_specializations_refresh(): void
{
    $scheduled = wp_next_scheduled(NOVAMIRA_SPECIALIZATIONS_HOOK) !== false;

    if (function_exists('novamira_pro_is_active') && novamira_pro_is_active()) {
        if ($scheduled) {
            novamira_unschedule_specializations_refresh();
        }
        return;
    }

    if ($scheduled) {
        return;
    }

    wp_schedule_event(
        timestamp: time() + (3 * DAY_IN_SECONDS),
        recurrence: NOVAMIRA_SPECIALIZATIONS_SCHEDULE,
        hook: NOVAMIRA_SPECIALIZATIONS_HOOK,
    );
}

function novamira_unschedule_specializations_refresh(): void
{
    wp_clear_scheduled_hook(NOVAMIRA_SPECIALIZATIONS_HOOK);
}

// Existing installs update rather than activate, so the event is also claimed
// lazily in the admin — once, because wp_next_scheduled() then answers yes.
add_action('admin_init', callback: 'novamira_schedule_specializations_refresh');
