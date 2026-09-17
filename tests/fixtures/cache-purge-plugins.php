<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Stand-ins for the purge APIs the cache plugins publish, with the names and signatures taken from
 * their own source: `rocket_clean_files()` (WP Rocket, inc/functions/files.php), `w3tc_flush_url()`
 * (W3 Total Cache, w3-total-cache-api.php), `wpsc_delete_url_cache()` (WP Super Cache,
 * wp-cache-phase2.php), the `purge_url()` method of Nginx Helper's purger object, and
 * `Supercacher::purge_cache_request()` (SG Optimizer).
 *
 * Loaded only by the scenarios that install the caches, so the others really run without them.
 */

namespace {
    /**
     * @param string|list<string> $urls
     */
    function rocket_clean_files(string|array $urls): void
    {
        novamira_test_record('wp-rocket', $urls);
    }

    function w3tc_flush_url(string $url): void
    {
        novamira_test_record('w3-total-cache', $url);
    }

    function wpsc_delete_url_cache(string $url): bool
    {
        if (str_contains($url, '?')) {
            return false;
        }
        novamira_test_record('wp-super-cache', $url);

        return true;
    }

    final class NovamiraTestNginxPurger
    {
        public function purge_url(string $url, bool $feed = true): void
        {
            novamira_test_record('nginx-helper', $url);
        }
    }
}

namespace SiteGround_Optimizer\Supercacher {
    final class Supercacher
    {
        public static function purge_cache_request(string $url, bool $include_child_paths = true): bool
        {
            \novamira_test_record('sg-cachepress', $url);

            return true;
        }
    }
}
