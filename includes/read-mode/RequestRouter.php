<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\ReadMode;

use Novamira\Vendor\WP\MCP\Transport\Infrastructure\HttpRequestContext;
use Novamira\Vendor\WP\MCP\Transport\Infrastructure\McpTransportContext;
use Novamira\Vendor\WP\MCP\Transport\Infrastructure\RequestRouter as AdapterRequestRouter;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The bundled adapter's request router, with read mode's check applied to the lists it returns.
 *
 * A server's tools, resources, and prompts lists pass through filters, and a plugin can attach its own
 * filter at PHP_INT_MAX while a list is being built: WordPress then runs it after every callback already
 * attached at that priority, read mode's included. So in read mode the final list is checked here, where
 * the router returns it to the HTTP or STDIO transport, after every filter. Read mode installs this
 * router on Novamira's MCP transports only while it is active.
 */
final class RequestRouter extends AdapterRequestRouter
{
    public function __construct(
        private McpTransportContext $transport_context,
    ) {
        parent::__construct($transport_context);
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<array-key, mixed>
     */
    public function route_request(
        string $method,
        array $params,
        $request_id = 0,
        string $transport_name = 'unknown',
        ?HttpRequestContext $http_context = null,
    ): array {
        $result = parent::route_request($method, $params, $request_id, $transport_name, $http_context);

        return \novamira_read_mode_check_list_result($this->transport_context->mcp_server, $method, $result);
    }
}
