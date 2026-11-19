<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/** Core REST collection stand-ins for the isolated read-mode policy runner. */
class WP_REST_Request extends ArrayObject
{
    public function get_method(): string
    {
        return 'GET';
    }

    public function get_route(): string
    {
        return '/wp-abilities/v1/abilities';
    }

    public function get_query_params(): array
    {
        return $this->getArrayCopy();
    }
}

class WP_REST_Response
{
    public function __construct(private array $data = [], private array $headers = []) {}

    public function get_status(): int
    {
        return 200;
    }

    public function get_data(): array
    {
        return $this->data;
    }

    public function set_data(array $data): void
    {
        $this->data = $data;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function get_headers(): array
    {
        return $this->headers;
    }

    public function set_headers(array $headers): void
    {
        $this->headers = $headers;
    }
}

class WP_REST_Abilities_V1_List_Controller
{
    public function prepare_item_for_response(WP_Ability $ability, WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['name' => $ability->get_name()]);
    }

    public function prepare_response_for_collection(WP_REST_Response $response): array
    {
        return $response->get_data();
    }
}

function rest_url(string $route): string
{
    return 'https://example.test/wp-json/' . $route;
}

function urlencode_deep(array $params): array
{
    return $params;
}

function add_query_arg(array $params, string $url): string
{
    return $url . '?' . http_build_query($params);
}

function did_action(string $hook): int
{
    return 0;
}
