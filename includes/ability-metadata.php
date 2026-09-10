<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

/** Check whether the current user may view an Ability's metadata. */
function novamira_current_user_can_view_ability_metadata(string $ability_name): bool
{
    return !str_starts_with($ability_name, 'novamira/') || novamira_current_user_can_manage();
}

/** Register metadata visibility enforcement for WordPress core's Abilities REST controllers. */
function novamira_register_ability_metadata_rest_filter(): void
{
    add_filter(
        'rest_request_before_callbacks',
        callback: 'novamira_prevent_restricted_rest_ability_run',
        accepted_args: 3,
    );
    add_filter('rest_request_after_callbacks', callback: 'novamira_filter_rest_ability_metadata', accepted_args: 3);
}

/**
 * Return the matched core REST controller when its callback handles the requested operation.
 *
 * @param class-string $controller_class
 */
function novamira_rest_handler_controller(mixed $handler, string $controller_class, string $method): ?object
{
    if (!is_array($handler)) {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- REST handlers store callbacks in an untyped array.
    $callback = $handler['callback'] ?? null;
    if (!is_array($callback)) {
        return null;
    }

    // @mago-expect analysis:mixed-assignment -- Callback members are untyped until checked below.
    $controller = $callback[0] ?? null;
    // @mago-expect analysis:mixed-assignment -- Callback members are untyped until checked below.
    $callback_method = $callback[1] ?? null;
    if (
        !is_object($controller)
        || !is_string($callback_method)
        || $callback_method !== $method
        || get_class($controller) !== $controller_class
    ) {
        return null;
    }

    return $controller;
}

/**
 * Refuse restricted core Ability runs before core normalizes or validates their input.
 *
 * Novamira's registered Abilities require management access, so dispatch cannot succeed for a user
 * whose metadata access is restricted. Short-circuiting here avoids invoking schema sanitizers during
 * dispatch. Core's rest_send_allow_header() still re-evaluates the route permission callback after
 * dispatch when building the Allow header. Successful run responses are deliberately not filtered:
 * reaching one means the route authorized execution, and masking that result would reject an
 * intentionally lower-privilege Ability without protecting any additional metadata.
 */
function novamira_prevent_restricted_rest_ability_run(mixed $response, mixed $handler, WP_REST_Request $request): mixed
{
    if (is_wp_error($response)) {
        return $response;
    }

    if (
        novamira_rest_handler_controller(
            $handler,
            controller_class: WP_REST_Abilities_V1_Run_Controller::class,
            method: 'execute_ability',
        ) === null
    ) {
        return $response;
    }

    // @mago-expect analysis:mixed-assignment -- Route parameters are untyped until checked below.
    $ability_name = $request['name'];
    if (!is_string($ability_name) || !str_starts_with($ability_name, 'novamira/')) {
        return $response;
    }
    if (novamira_current_user_can_view_ability_metadata($ability_name)) {
        return $response;
    }

    return novamira_rest_ability_not_found_error();
}

/** Hide Novamira metadata returned by WordPress core's read-only Abilities REST controllers. */
function novamira_filter_rest_ability_metadata(mixed $response, mixed $handler, WP_REST_Request $request): mixed
{
    $item_controller = novamira_rest_handler_controller(
        $handler,
        controller_class: WP_REST_Abilities_V1_List_Controller::class,
        method: 'get_item',
    );
    if ($item_controller !== null) {
        return novamira_filter_rest_ability_item_response($response, $request);
    }

    $list_controller = novamira_rest_handler_controller(
        $handler,
        controller_class: WP_REST_Abilities_V1_List_Controller::class,
        method: 'get_items',
    );
    if ($list_controller instanceof WP_REST_Abilities_V1_List_Controller) {
        return novamira_filter_rest_ability_list_response($response, $list_controller, $request);
    }

    $category_item_controller = novamira_rest_handler_controller(
        $handler,
        controller_class: WP_REST_Abilities_V1_Categories_Controller::class,
        method: 'get_item',
    );
    if ($category_item_controller !== null) {
        return novamira_filter_rest_ability_category_item_response($response, $request);
    }

    $category_list_controller = novamira_rest_handler_controller(
        $handler,
        controller_class: WP_REST_Abilities_V1_Categories_Controller::class,
        method: 'get_items',
    );
    if (!$category_list_controller instanceof WP_REST_Abilities_V1_Categories_Controller) {
        return $response;
    }

    return novamira_filter_rest_ability_category_list_response($response, $category_list_controller, $request);
}

/** Hide a successful core item response for a restricted Ability. */
function novamira_filter_rest_ability_item_response(mixed $response, WP_REST_Request $request): mixed
{
    // @mago-expect analysis:mixed-assignment -- Route parameters are untyped until checked below.
    $ability_name = $request['name'];
    if (!is_string($ability_name) || !str_starts_with($ability_name, 'novamira/')) {
        return $response;
    }
    if (novamira_current_user_can_view_ability_metadata($ability_name)) {
        return $response;
    }
    if (!$response instanceof WP_REST_Response || $response->get_status() >= 400) {
        return $response;
    }

    return novamira_rest_ability_not_found_error();
}

/** Rebuild a successful core Ability list response for a user without metadata access. */
function novamira_filter_rest_ability_list_response(
    mixed $response,
    WP_REST_Abilities_V1_List_Controller $controller,
    WP_REST_Request $request,
): mixed {
    if (novamira_current_user_can_manage()) {
        return $response;
    }
    if (!$response instanceof WP_REST_Response || $response->get_status() >= 400) {
        return $response;
    }

    return novamira_filter_rest_ability_collection($response, $controller, $request);
}

/** Hide a successful core item response for a Novamira-registered Ability category. */
function novamira_filter_rest_ability_category_item_response(mixed $response, WP_REST_Request $request): mixed
{
    // @mago-expect analysis:mixed-assignment -- Route parameters are untyped until checked below.
    $category_slug = $request['slug'];
    if (!is_string($category_slug) || !novamira_ability_category_metadata_is_restricted($category_slug)) {
        return $response;
    }
    if (novamira_current_user_can_manage()) {
        return $response;
    }
    if (!$response instanceof WP_REST_Response || $response->get_status() >= 400) {
        return $response;
    }

    return novamira_rest_ability_category_not_found_error();
}

/** Rebuild a successful core category list response for a user without metadata access. */
function novamira_filter_rest_ability_category_list_response(
    mixed $response,
    WP_REST_Abilities_V1_Categories_Controller $controller,
    WP_REST_Request $request,
): mixed {
    if (novamira_current_user_can_manage()) {
        return $response;
    }
    if (!$response instanceof WP_REST_Response || $response->get_status() >= 400) {
        return $response;
    }

    return novamira_filter_rest_ability_category_collection($response, $controller, $request);
}

/** Rebuild the paginated core Ability collection from metadata visible to the current user. */
function novamira_filter_rest_ability_collection(
    WP_REST_Response $response,
    WP_REST_Abilities_V1_List_Controller $controller,
    WP_REST_Request $request,
): WP_REST_Response {
    $abilities = array_filter(
        wp_get_abilities(),
        static fn(WP_Ability $ability): bool => (
            $ability->get_meta_item('show_in_rest') === true
            && novamira_current_user_can_view_ability_metadata($ability->get_name())
        ),
    );

    // @mago-expect analysis:mixed-assignment -- Collection parameters are untyped until checked below.
    $category = $request['category'];
    if (is_string($category) && $category !== '') {
        $abilities = array_filter(
            $abilities,
            static fn(WP_Ability $ability): bool => $ability->get_category() === $category,
        );
    }

    // Core has already validated and applied defaults to these collection parameters.
    $page = max(1, (int) $request['page']);
    $per_page = max(1, (int) $request['per_page']);
    $total = count($abilities);
    $max_pages = (int) ceil($total / $per_page);
    $data = [];
    if ($request->get_method() !== 'HEAD') {
        foreach (array_slice(array_values($abilities), ($page - 1) * $per_page, $per_page) as $ability) {
            $item = $controller->prepare_item_for_response($ability, $request);
            $data[] = $controller->prepare_response_for_collection($item);
        }
    }

    $response->set_data($data);
    $response->header('X-WP-Total', (string) $total);
    $response->header('X-WP-TotalPages', (string) $max_pages);
    novamira_set_rest_collection_links(
        $response,
        $request,
        route_base: 'wp-abilities/v1/abilities',
        page: $page,
        max_pages: $max_pages,
    );

    return $response;
}

/**
 * Return the Ability category slugs whose metadata follows Novamira visibility.
 *
 * @return list<string>
 */
function novamira_ability_category_slugs(): array
{
    $defaults = [
        'admin-access',
        'code-execution',
        'context',
        'design-system',
        'filesystem',
        'gutenberg',
        'novamira-mcp-adapter',
        'skill',
    ];

    /**
     * Filter Ability category slugs registered by Novamira modules.
     *
     * @param list<string> $defaults
     */
    // @mago-expect analysis:mixed-assignment -- WordPress filters are untyped until their result is checked.
    $slugs = apply_filters('novamira_ability_category_slugs', $defaults);
    if (!is_array($slugs)) {
        return $defaults;
    }

    return array_values(array_filter($slugs, static fn(mixed $slug): bool => is_string($slug)));
}

/** Return whether a category slug is registered by a Novamira module. */
function novamira_ability_category_metadata_is_restricted(string $category_slug): bool
{
    if (!in_array($category_slug, novamira_ability_category_slugs(), strict: true)) {
        return false;
    }

    // A shared slug remains visible when it categorizes a third-party REST Ability.
    foreach (wp_get_abilities() as $ability) {
        if ($ability->get_category() !== $category_slug || $ability->get_meta_item('show_in_rest') !== true) {
            continue;
        }

        $ability_name = $ability->get_name();
        if (!str_starts_with($ability_name, 'novamira/') && !str_starts_with($ability_name, 'novamira-mcp-adapter/')) {
            return false;
        }
    }

    return true;
}

/** Rebuild the paginated core category collection without Novamira-registered categories. */
function novamira_filter_rest_ability_category_collection(
    WP_REST_Response $response,
    WP_REST_Abilities_V1_Categories_Controller $controller,
    WP_REST_Request $request,
): WP_REST_Response {
    $categories = array_filter(
        wp_get_ability_categories(),
        static fn(WP_Ability_Category $category): bool => !novamira_ability_category_metadata_is_restricted(
            $category->get_slug(),
        ),
    );

    // Core has already validated and applied defaults to these collection parameters.
    $page = max(1, (int) $request['page']);
    $per_page = max(1, (int) $request['per_page']);
    $total = count($categories);
    $max_pages = (int) ceil($total / $per_page);
    $data = [];
    if ($request->get_method() !== 'HEAD') {
        foreach (array_slice(array_values($categories), ($page - 1) * $per_page, $per_page) as $category) {
            $item = $controller->prepare_item_for_response($category, $request);
            $data[] = $controller->prepare_response_for_collection($item);
        }
    }

    $response->set_data($data);
    $response->header('X-WP-Total', (string) $total);
    $response->header('X-WP-TotalPages', (string) $max_pages);
    novamira_set_rest_collection_links(
        $response,
        $request,
        route_base: 'wp-abilities/v1/categories',
        page: $page,
        max_pages: $max_pages,
    );

    return $response;
}

/** Replace core collection navigation with links based on the visible collection. */
function novamira_set_rest_collection_links(
    WP_REST_Response $response,
    WP_REST_Request $request,
    string $route_base,
    int $page,
    int $max_pages,
): void {
    $headers = $response->get_headers();
    unset($headers['Link'], $headers['link']);
    $response->set_headers($headers);

    $base = add_query_arg(urlencode_deep($request->get_query_params()), rest_url($route_base));
    if ($page > 1) {
        $response->link_header('prev', add_query_arg('page', $page - 1, $base));
    }
    if ($page < $max_pages) {
        $response->link_header('next', add_query_arg('page', $page + 1, $base));
    }
}

/** Return the same not-found response as WordPress core's Ability controllers. */
function novamira_rest_ability_not_found_error(): WP_Error
{
    // Deliberately use core's default translation domain so hidden and missing Abilities stay indistinguishable.
    return new WP_Error('rest_ability_not_found', __('Ability not found.'), ['status' => 404]);
}

/** Return the same not-found response as WordPress core's Ability category controller. */
function novamira_rest_ability_category_not_found_error(): WP_Error
{
    return new WP_Error('rest_ability_category_not_found', __('Ability category not found.'), ['status' => 404]);
}
