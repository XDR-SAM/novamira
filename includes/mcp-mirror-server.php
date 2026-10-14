<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Mirrors of the canonical Novamira MCP server (the legacy alias and the OAuth endpoint).
 */

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Create an MCP server that mirrors the canonical Novamira server — same tools, resources, and
 * prompts — under a different id and route. Shared by the legacy alias and the OAuth endpoint so
 * neither drifts from the default server's exposed abilities.
 */
function novamira_create_mirror_mcp_server(
    \Novamira\Vendor\WP\MCP\Core\McpAdapter $adapter,
    string $server_id,
    string $route,
    string $name,
    string $description,
): void {
    $components = novamira_mcp_server_components(
        [
            'novamira-mcp-adapter/discover-abilities',
            'novamira-mcp-adapter/get-ability-info',
            'novamira-mcp-adapter/execute-ability',
        ],
        discover: 'novamira_discover_public_abilities',
    );

    $adapter->create_server(
        $server_id,
        'mcp',
        $route,
        $name,
        $description,
        'v1.0.0',
        [\Novamira\Vendor\WP\MCP\Transport\HttpTransport::class],
        \Novamira\Vendor\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \Novamira\Vendor\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        $components['tools'],
        $components['resources'],
        $components['prompts'],
    );
}

/**
 * Replicate DefaultServerFactory::discover_abilities_by_type for reuse on the legacy alias.
 *
 * @return list<string>
 */
function novamira_discover_public_abilities(string $type): array
{
    if (!function_exists('wp_get_abilities')) {
        return [];
    }

    $abilities = wp_get_abilities();
    $filtered = [];
    foreach ($abilities as $ability) {
        $meta = $ability->get_meta();
        if (!($meta['mcp']['public'] ?? false)) {
            continue;
        }
        $ability_type = (string) ($meta['mcp']['type'] ?? 'tool');
        if ($ability_type !== $type) {
            continue;
        }
        $filtered[] = $ability->get_name();
    }

    return $filtered;
}
