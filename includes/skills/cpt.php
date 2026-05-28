<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Cpt;

if (!defined('ABSPATH')) {
    exit();
}

const POST_TYPE = 'novamira_skill';

const META_ENABLE_PROMPT = '_enable_prompt';

const META_ENABLE_AGENTIC = '_enable_agentic';

function register(): void
{
    $capability = \novamira_manage_capability();

    register_post_type(POST_TYPE, [
        'label' => __('Skills', domain: 'novamira'),
        'public' => false,
        'show_ui' => false,
        'show_in_rest' => false,
        'has_archive' => false,
        'rewrite' => false,
        'capability_type' => ['novamira_skill', 'novamira_skills'],
        'map_meta_cap' => true,
        'capabilities' => [
            'read' => $capability,
            'edit_posts' => $capability,
            'edit_others_posts' => $capability,
            'edit_private_posts' => $capability,
            'edit_published_posts' => $capability,
            'publish_posts' => $capability,
            'read_private_posts' => $capability,
            'delete_posts' => $capability,
            'delete_others_posts' => $capability,
            'delete_private_posts' => $capability,
            'delete_published_posts' => $capability,
            'create_posts' => $capability,
        ],
        // `revisions` enables WP's native history for post_title /
        // post_content / post_excerpt on every save. Post meta is not
        // tracked (acceptable in v1 — flags are settings, not content).
        // Browsing / restoring is deferred to a future version; today
        // we just start collecting so future-UI has data to show.
        'supports' => ['title', 'editor', 'excerpt', 'revisions'],
    ]);

    add_filter(
        'wp_revisions_to_keep',
        static fn(int $num, \WP_Post $post): int => $post->post_type === POST_TYPE ? 10 : $num,
        accepted_args: 2,
    );

    $auth = static fn(): bool => \novamira_current_user_can_manage();

    register_post_meta(POST_TYPE, META_ENABLE_PROMPT, [
        'type' => 'boolean',
        'single' => true,
        'default' => true,
        'show_in_rest' => false,
        'auth_callback' => $auth,
    ]);
    register_post_meta(POST_TYPE, META_ENABLE_AGENTIC, [
        'type' => 'boolean',
        'single' => true,
        'default' => true,
        'show_in_rest' => false,
        'auth_callback' => $auth,
    ]);
}
