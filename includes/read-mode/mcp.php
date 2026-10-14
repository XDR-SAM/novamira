<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Read mode on the MCP servers of the bundled adapter.
 *
 * The tool list stays the same with read mode on or off: discover-abilities, get-ability-info, and
 * execute-ability, which dispatches only to trusted allowlisted Abilities. Resources and prompts come
 * from the allowlist. Every MCP entry reconciles each server's components with that: a tool, resource,
 * or prompt survives only when it is backed by a trusted implementation of an allowlisted Ability, so
 * callable-backed components and components added by configuration filters are removed before any
 * component permission callback could run. The adapter offers no API to remove components, so the
 * reconciliation edits its component registry through reflection; when that is not possible it fails
 * closed and refuses MCP in read mode.
 *
 * Over HTTP that happens once per request, when the MCP route is entered. The STDIO server is one
 * long-running process that serves many calls, and read mode can be turned on while it runs: there the
 * state is read on every check, and the registry and components are reconciled again at every list,
 * tool call, prompt get, and resource read.
 */

if (!defined('ABSPATH')) {
    exit();
}

/** The tools every Novamira MCP server exposes, with read mode on or off. */
const NOVAMIRA_MCP_META_TOOLS = [
    'novamira-mcp-adapter/discover-abilities',
    'novamira-mcp-adapter/get-ability-info',
    'novamira-mcp-adapter/execute-ability',
];

/** The adapter's component classes and the component-registry property that holds each kind. */
const NOVAMIRA_READ_MODE_MCP_COMPONENTS = [
    'mcp_tools' => \Novamira\Vendor\WP\MCP\Domain\Tools\McpTool::class,
    'mcp_resources' => \Novamira\Vendor\WP\MCP\Domain\Resources\McpResource::class,
    'mcp_prompts' => \Novamira\Vendor\WP\MCP\Domain\Prompts\McpPrompt::class,
];

/**
 * The tools, resources, and prompts of a Novamira MCP server.
 *
 * Tools are the same in both modes. In read mode resources and prompts come from the allowlist alone:
 * a server can be built while Abilities are still being registered, before the read-mode sweep, and
 * the adapter keeps the Ability objects it was built from.
 *
 * @param list<string> $default_tools
 * @param callable(string): list<string> $discover Lists public Abilities of an MCP type outside read mode.
 * @return array{tools: list<string>, resources: list<string>, prompts: list<string>}
 */
function novamira_mcp_server_components(array $default_tools, callable $discover): array
{
    if (!novamira_is_read_mode()) {
        return ['tools' => $default_tools, 'resources' => $discover('resource'), 'prompts' => $discover('prompt')];
    }

    return [
        'tools' => $default_tools,
        'resources' => novamira_read_mode_mcp_abilities('resource'),
        'prompts' => novamira_read_mode_mcp_abilities('prompt'),
    ];
}

/**
 * The trusted, registered, MCP-public allowlisted Abilities of one MCP type.
 *
 * @return list<string>
 */
function novamira_read_mode_mcp_abilities(string $type): array
{
    if (!function_exists('wp_has_ability') || !function_exists('wp_get_ability')) {
        return [];
    }

    $abilities = [];
    foreach ([...novamira_read_mode_allowed_abilities(), ...novamira_read_mode_declared_abilities()] as $ability_name) {
        // wp_get_ability() reports a missing name as a developer notice, so check first.
        $ability = wp_has_ability($ability_name) ? wp_get_ability($ability_name) : null;
        if (!$ability instanceof WP_Ability || !novamira_read_mode_trusts_ability($ability)) {
            continue;
        }

        $meta = $ability->get_meta();
        $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
        $ability_type = is_string($mcp['type'] ?? null) ? $mcp['type'] : 'tool';
        if ($ability_type !== $type || ($mcp['public'] ?? false) !== true) {
            continue;
        }

        $abilities[] = $ability_name;
    }

    return $abilities;
}

/**
 * Restrict the default MCP server's tools, resources, and prompts in read mode, replacing whatever
 * earlier configuration filters added.
 */
function novamira_read_mode_default_server_config(mixed $config): mixed
{
    if (!is_array($config) || !novamira_is_read_mode()) {
        return $config;
    }

    return array_merge($config, novamira_mcp_server_components(NOVAMIRA_MCP_META_TOOLS, static fn(string $type): array => []));
}

/**
 * Move the default-server configuration filter after every other one when the adapter initializes,
 * so a configuration filter attached later at PHP_INT_MAX cannot add components after it.
 */
function novamira_read_mode_reattach_server_config_filter(): void
{
    remove_filter(
        'novamira_mcp_adapter_default_server_config',
        callback: 'novamira_read_mode_default_server_config',
        priority: PHP_INT_MAX,
    );
    add_filter(
        'novamira_mcp_adapter_default_server_config',
        callback: 'novamira_read_mode_default_server_config',
        priority: PHP_INT_MAX,
    );
    novamira_read_mode_reattach_guard_filter();
}

/**
 * The Ability an MCP component is backed by, or null for a callable-backed or builder-backed component,
 * or a subclass of the adapter's component classes (which could override its checks).
 */
function novamira_read_mode_mcp_component_ability(mixed $component): ?WP_Ability
{
    if (!is_object($component)) {
        return null;
    }

    foreach (NOVAMIRA_READ_MODE_MCP_COMPONENTS as $class) {
        if (get_class($component) !== $class) {
            continue;
        }

        $ability = novamira_read_mode_private_property($component, $class, property: 'ability');

        return $ability !== null && $ability['value'] instanceof WP_Ability ? $ability['value'] : null;
    }

    return null;
}

/** Whether read mode keeps an MCP component: backed by a trusted implementation of an allowlisted Ability. */
function novamira_read_mode_trusts_mcp_component(mixed $component): bool
{
    $ability = novamira_read_mode_mcp_component_ability($component);

    return (
        $ability !== null
        && novamira_read_mode_permits_ability($ability->get_name())
        && novamira_read_mode_trusts_ability($ability)
    );
}

/**
 * Remove every component without a trusted allowlisted Ability from every server of the bundled adapter.
 *
 * Returns false when a server's components cannot be inspected or edited, so callers can refuse MCP.
 */
function novamira_read_mode_reconcile_mcp_servers(): bool
{
    if (!novamira_is_read_mode() || !did_action('novamira_mcp_adapter_init')) {
        return true;
    }

    $adapter_class = \Novamira\Vendor\WP\MCP\Core\McpAdapter::class;
    if (!class_exists($adapter_class)) {
        return true;
    }

    foreach ($adapter_class::instance()->get_servers() as $server) {
        if (!novamira_read_mode_reconcile_mcp_server($server)) {
            return false;
        }
    }

    return true;
}

/** Remove a server's untrusted components. Returns false when they cannot be inspected or edited. */
function novamira_read_mode_reconcile_mcp_server(object $server): bool
{
    $registry = novamira_read_mode_private_property(
        $server,
        \Novamira\Vendor\WP\MCP\Core\McpServer::class,
        property: 'component_registry',
    );
    if ($registry === null || !is_object($registry['value'])) {
        return false;
    }

    $registry_class = \Novamira\Vendor\WP\MCP\Core\McpComponentRegistry::class;
    foreach (array_keys(NOVAMIRA_READ_MODE_MCP_COMPONENTS) as $property) {
        $components = novamira_read_mode_private_property($registry['value'], $registry_class, $property);
        if ($components === null || !is_array($components['value'])) {
            return false;
        }

        $trusted = array_filter($components['value'], callback: 'novamira_read_mode_trusts_mcp_component');
        $unchanged = count($trusted) === count($components['value']);
        if (
            !$unchanged
            && !novamira_read_mode_set_private_property($registry['value'], $registry_class, $property, $trusted)
        ) {
            return false;
        }
    }

    return true;
}

/**
 * Sweep the registry and reconcile every server of the bundled adapter with read mode, now. Returns
 * false when the servers cannot be verified.
 */
function novamira_read_mode_enforce_mcp(): bool
{
    novamira_enforce_read_mode_registry();

    return novamira_read_mode_reconcile_mcp_servers();
}

/**
 * Filter what a server lists (novamira_mcp_adapter_tools_list, _resources_list, _prompts_list) in read
 * mode: reconcile first, then keep only items whose component the server still holds. Nothing is
 * listed when the servers cannot be verified. Outside read mode the list is returned unchanged.
 */
function novamira_read_mode_filter_mcp_list(mixed $items, mixed $server = null): mixed
{
    if (!is_array($items) || !novamira_is_read_mode()) {
        return $items;
    }
    if (!novamira_read_mode_enforce_mcp() || !$server instanceof \Novamira\Vendor\WP\MCP\Core\McpServer) {
        return [];
    }

    return array_values(array_filter($items, static fn(mixed $item): bool => novamira_read_mode_server_holds_item(
        $server,
        $item,
    )));
}

/** The lists the request router returns: the result key, and the field that names each item. */
const NOVAMIRA_READ_MODE_MCP_LISTS = [
    'tools/list' => ['tools', 'name'],
    'tools/list/all' => ['tools', 'name'],
    'resources/list' => ['resources', 'uri'],
    'prompts/list' => ['prompts', 'name'],
];

/**
 * In read mode, keep in a list the request router returns only the items whose component the server
 * holds after reconciliation, after every list filter has run (see Novamira\ReadMode\RequestRouter).
 * Nothing is listed when the servers cannot be verified. Other results, and every result outside read
 * mode, are returned unchanged.
 *
 * @param array<array-key, mixed> $result
 * @return array<array-key, mixed>
 */
function novamira_read_mode_check_list_result(
    \Novamira\Vendor\WP\MCP\Core\McpServer $server,
    string $method,
    array $result,
): array {
    $list = NOVAMIRA_READ_MODE_MCP_LISTS[$method] ?? null;
    // @mago-expect analysis:mixed-assignment -- the listed items are checked below.
    $items = $list === null ? null : $result[$list[0]] ?? null;
    if ($list === null || !is_array($items) || !novamira_is_read_mode()) {
        return $result;
    }

    [$key, $field] = $list;
    if (!novamira_read_mode_enforce_mcp()) {
        $result[$key] = [];

        return $result;
    }

    $result[$key] = array_values(array_filter(
        $items,
        static fn(mixed $item): bool => (
            is_array($item)
            && is_string($item[$field] ?? null)
            && match ($key) {
                'tools' => $server->get_mcp_tool($item[$field]) !== null,
                'resources' => $server->get_mcp_resource($item[$field]) !== null,
                default => $server->get_mcp_prompt($item[$field]) !== null,
            }
        ),
    ));

    return $result;
}

/** Route a transport context's requests through read mode's list check, once. */
function novamira_read_mode_install_list_check(\Novamira\Vendor\WP\MCP\Transport\Infrastructure\McpTransportContext $context): void
{
    require_once __DIR__ . '/RequestRouter.php';
    if ($context->request_router instanceof \Novamira\ReadMode\RequestRouter) {
        return;
    }

    $context->request_router = new \Novamira\ReadMode\RequestRouter($context);
}

/**
 * In read mode, route the requests of the bundled adapter's MCP HTTP transport that serves this REST
 * route through read mode's list check. Routes of other MCP adapters are left alone. Returns false when
 * the transport is the bundled adapter's but its request router cannot be reached.
 *
 * @param mixed $handler The REST route handler, whose callback is the transport's handle_request().
 */
function novamira_read_mode_check_http_lists(mixed $handler): bool
{
    // @mago-expect analysis:mixed-assignment -- the callback's object is checked below.
    $transport = is_array($handler) && is_array($handler['callback'] ?? null) ? $handler['callback'][0] ?? null : null;
    if (!novamira_is_read_mode() || !$transport instanceof \Novamira\Vendor\WP\MCP\Transport\HttpTransport) {
        return true;
    }

    $request_handler = novamira_read_mode_private_property(
        $transport,
        \Novamira\Vendor\WP\MCP\Transport\HttpTransport::class,
        property: 'request_handler',
    );
    if (
        $request_handler === null
        || !$request_handler['value'] instanceof \Novamira\Vendor\WP\MCP\Transport\Infrastructure\HttpRequestHandler
    ) {
        return false;
    }

    novamira_read_mode_install_list_check($request_handler['value']->transport_context);

    return true;
}

/**
 * In read mode, route the requests of the STDIO bridge that is starting to serve (the caller of the
 * novamira_mcp_adapter_enable_stdio_transport filter) through read mode's list check. Returns false when
 * the bridge or its request router cannot be reached.
 */
function novamira_read_mode_check_stdio_lists(): bool
{
    if (!novamira_is_read_mode()) {
        return true;
    }

    $bridge_class = \Novamira\Vendor\WP\MCP\Cli\StdioServerBridge::class;
    $router_class = \Novamira\Vendor\WP\MCP\Transport\Infrastructure\RequestRouter::class;
    // @mago-expect analysis:mixed-assignment -- each frame is checked below.
    foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, limit: 20) as $frame) {
        // @mago-expect analysis:mixed-assignment -- the frame's object is checked below.
        $bridge = $frame['object'] ?? null;
        if (!$bridge instanceof $bridge_class) {
            continue;
        }

        $router = novamira_read_mode_private_property($bridge, $bridge_class, property: 'request_router');
        $context =
            $router !== null && $router['value'] instanceof $router_class
                ? novamira_read_mode_private_property($router['value'], $router_class, property: 'context')
                : null;
        if (
            $context === null
            || !$context['value'] instanceof \Novamira\Vendor\WP\MCP\Transport\Infrastructure\McpTransportContext
        ) {
            return false;
        }

        novamira_read_mode_install_list_check($context['value']);

        return novamira_read_mode_set_private_property(
            $bridge,
            $bridge_class,
            property: 'request_router',
            value: $context['value']->request_router,
        );
    }

    return false;
}

/** Whether a listed tool, resource, or prompt is backed by a component the server holds. */
function novamira_read_mode_server_holds_item(\Novamira\Vendor\WP\MCP\Core\McpServer $server, mixed $item): bool
{
    return match (true) {
        $item instanceof \Novamira\Vendor\WP\McpSchema\Server\Tools\DTO\Tool => $server->get_mcp_tool($item->getName())
            !== null,
        $item instanceof \Novamira\Vendor\WP\McpSchema\Server\Prompts\DTO\Prompt => $server->get_mcp_prompt(
            $item->getName(),
        ) !== null,
        $item instanceof \Novamira\Vendor\WP\McpSchema\Server\Resources\DTO\Resource => $server->get_mcp_resource(
            $item->getUri(),
        ) !== null,
        default => false,
    };
}

/**
 * Refuse an MCP tool call, prompt get, or resource read of a component without a trusted allowlisted
 * Ability. Components without an Ability, such as callable-backed tools, are refused too.
 *
 * Filters novamira_mcp_adapter_pre_tool_call, novamira_mcp_adapter_pre_prompt_get, and
 * novamira_mcp_adapter_pre_resource_read, whose third argument is the MCP component. Over HTTP the
 * reconciliation has already removed such components when the route was entered. Over STDIO read mode
 * can turn on after the call began, so the refused component is also disarmed: a later filter on these
 * hooks that replaces the refusal cannot make the component run.
 */
function novamira_read_mode_refuse_mcp_component(mixed $arguments, mixed $name, mixed $component): mixed
{
    if (!novamira_is_read_mode()) {
        return $arguments;
    }
    if (novamira_read_mode_enforce_mcp() && novamira_read_mode_trusts_mcp_component($component)) {
        return $arguments;
    }

    $ability = novamira_read_mode_mcp_component_ability($component);
    $subject = $ability !== null
        ? sprintf("the ability '%s'", $ability->get_name())
        : sprintf("the MCP component '%s'", is_string($name) ? $name : '');
    $refusal = novamira_read_mode_blocked_error($subject);
    if (is_object($component)) {
        novamira_read_mode_disarm_mcp_component($component, $refusal);
    }

    return $refusal;
}

/**
 * Make an adapter component return a refusal whenever it is executed: its handler is replaced, and its
 * Ability and prompt builder are dropped, so execute() reaches the handler. Components of other classes
 * are left alone; read mode refuses them before they are listed or called.
 */
function novamira_read_mode_disarm_mcp_component(object $component, WP_Error $refusal): void
{
    $class = get_class($component);
    if (!in_array($class, NOVAMIRA_READ_MODE_MCP_COMPONENTS, strict: true)) {
        return;
    }

    novamira_read_mode_set_private_property($component, $class, property: 'ability', value: null);
    if (property_exists($class, 'builder')) {
        novamira_read_mode_set_private_property($component, $class, property: 'builder', value: null);
    }
    novamira_read_mode_set_private_property(
        $component,
        $class,
        property: 'handler',
        value: static fn(): WP_Error => $refusal,
    );
}

/**
 * The read-mode refusal for an execute-ability call, or null when the target is a trusted allowlisted
 * Ability (or read mode is off). Runs before the adapter's own permission check.
 */
function novamira_read_mode_execute_ability_refusal(string $target): ?WP_Error
{
    if (!novamira_is_read_mode()) {
        return null;
    }

    if (!novamira_read_mode_enforce_mcp()) {
        return novamira_read_mode_blocked_error(__(
            'MCP, because its server components could not be verified',
            domain: 'novamira',
        ));
    }
    $refusal = novamira_read_mode_unavailable_ability_error($target === '' ? '(none)' : $target);
    if ($refusal !== null) {
        return $refusal;
    }

    $ability = function_exists('wp_has_ability') && wp_has_ability($target) ? wp_get_ability($target) : null;
    if (!$ability instanceof WP_Ability) {
        return novamira_read_mode_blocked_error(sprintf("the ability '%s'", $target));
    }

    return novamira_read_mode_ability_refusal($ability);
}
