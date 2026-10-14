<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Minimal WordPress stand-ins for the read-mode runners. Loaded in a child process only, so every
 * scenario starts from clean globals and constants.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/');
}

require_once __DIR__ . '/read-mode-state-fixture.php';

$GLOBALS['options'] = [];
$GLOBALS['network_options'] = [];
$GLOBALS['filters'] = [];
$GLOBALS['hooks'] = [];
$GLOBALS['abilities'] = [];
$GLOBALS['ability_categories'] = [];
$GLOBALS['unregistered'] = [];
$GLOBALS['calls'] = [];
$GLOBALS['is_admin'] = false;
$GLOBALS['can_manage'] = true;
$GLOBALS['logged_in'] = true;

class WP_Error
{
    public function __construct(
        private string $code = '',
        private string $message = '',
        private mixed $data = null,
    ) {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

class WP_Ability
{
    /** As in WordPress 6.9, where read mode's identity check reads them. */
    protected mixed $execute_callback = null;

    protected mixed $permission_callback = null;

    /** @param array<string, mixed> $args */
    public function __construct(
        private string $name,
        private array $args = [],
    ) {
        $this->execute_callback = $args['execute_callback'] ?? null;
        $this->permission_callback = $args['permission_callback'] ?? null;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_label(): string
    {
        return (string) ($this->args['label'] ?? $this->name);
    }

    public function get_description(): string
    {
        return (string) ($this->args['description'] ?? '');
    }

    public function get_category(): string
    {
        return (string) ($this->args['category'] ?? '');
    }

    public function get_input_schema(): array
    {
        return (array) ($this->args['input_schema'] ?? []);
    }

    public function get_output_schema(): array
    {
        return (array) ($this->args['output_schema'] ?? []);
    }

    public function get_meta(): array
    {
        return (array) ($this->args['meta'] ?? []);
    }

    public function get_meta_item(string $key, mixed $default = null): mixed
    {
        return $this->get_meta()[$key] ?? $default;
    }

    public function execute(mixed $input = null): mixed
    {
        return ($this->args['execute_callback'])($input);
    }

    /** @return array<string, mixed> */
    public function get_args(): array
    {
        return $this->args;
    }
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return $text;
}

function add_filter(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['filters'][$hook][] = $callback;
    $GLOBALS['hooks'][] = [$hook, $callback, $priority, $accepted_args];
    return true;
}

function add_action(string $hook, callable|string $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['hooks'][] = [$hook, $callback, $priority, $accepted_args];
    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['filters'][$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }
    return $value;
}

function get_option(string $option, mixed $default_value = false): mixed
{
    $GLOBALS['calls'][] = ['get_option', $option];
    return array_key_exists($option, $GLOBALS['options']) ? $GLOBALS['options'][$option] : $default_value;
}

function update_option(string $option, mixed $value, mixed $autoload = null): bool
{
    $GLOBALS['calls'][] = ['update_option', $option];
    $GLOBALS['options'][$option] = $value;
    return true;
}

function delete_option(string $option): bool
{
    $GLOBALS['calls'][] = ['delete_option', $option];
    unset($GLOBALS['options'][$option]);
    return true;
}

function get_network_option(?int $network_id, string $option, mixed $default_value = false): mixed
{
    $GLOBALS['calls'][] = ['get_network_option', $option];
    return array_key_exists($option, $GLOBALS['network_options']) ? $GLOBALS['network_options'][$option] : $default_value;
}

function get_transient(string $name): mixed
{
    $GLOBALS['calls'][] = ['get_transient', $name];
    return false;
}

function delete_transient(string $name): bool
{
    $GLOBALS['calls'][] = ['delete_transient', $name];
    return true;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'salt-' . $scheme;
}

function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}

function is_multisite(): bool
{
    return (bool) ($GLOBALS['multisite'] ?? false);
}

function current_user_can(string $capability): bool
{
    return $GLOBALS['can_manage'];
}

function is_super_admin(int $user_id = 0): bool
{
    return $GLOBALS['can_manage'];
}

function is_user_logged_in(): bool
{
    return $GLOBALS['logged_in'];
}

function is_admin(): bool
{
    return $GLOBALS['is_admin'];
}

function sanitize_key(string $key): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $key));
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function wp_register_ability(string $name, array $args): ?WP_Ability
{
    $args = apply_filters('wp_register_ability_args', $args, $name);
    $GLOBALS['abilities'][$name] = new WP_Ability($name, $args);
    return $GLOBALS['abilities'][$name];
}

function wp_unregister_ability(string $name): ?WP_Ability
{
    $ability = $GLOBALS['abilities'][$name] ?? null;
    unset($GLOBALS['abilities'][$name]);
    $GLOBALS['unregistered'][] = $name;
    return $ability;
}

function wp_has_ability(string $name): bool
{
    return isset($GLOBALS['abilities'][$name]);
}

function wp_get_ability(string $name): ?WP_Ability
{
    return $GLOBALS['abilities'][$name] ?? null;
}

/** @return array<string, WP_Ability> */
function wp_get_abilities(): array
{
    return $GLOBALS['abilities'];
}

function wp_has_ability_category(string $slug): bool
{
    return isset($GLOBALS['ability_categories'][$slug]);
}

function wp_register_ability_category(string $slug, array $args): ?object
{
    $GLOBALS['ability_categories'][$slug] = $args;
    return (object) $args;
}

/**
 * Register a placeholder Ability with the given category and meta. An allowlisted name gets Novamira's
 * own callbacks, so read mode treats it as the trusted implementation.
 */
function test_register(string $name, string $category = '', array $meta = []): void
{
    $args = ['category' => $category, 'meta' => $meta];
    $trusted = function_exists('novamira_read_mode_trusted_abilities')
        ? novamira_read_mode_trusted_abilities()[$name] ?? null
        : null;
    if ($trusted !== null) {
        $args['execute_callback'] = $trusted['execute'];
        $args['permission_callback'] = $trusted['permission'];
    }
    wp_register_ability($name, $args);
}

/** Encode a WP_Error or value for the parent test. */
function test_export(mixed $value): mixed
{
    if ($value instanceof WP_Error) {
        return [
            'error' => $value->get_error_code(),
            'message' => $value->get_error_message(),
            'data' => $value->get_error_data(),
        ];
    }

    return $value;
}

/** The data abilities Novamira Pro adds to read mode through the novamira_read_mode_abilities filter. */
const TEST_PRO_READ_ABILITIES = [
    'novamira/get-object',
    'novamira/query-objects',
    'novamira/get-options',
    'novamira/list-options',
    'novamira/describe-schema',
    'novamira/read-query',
];

/** A stand-in for the execute callback of a read ability Novamira Pro adds. */
function test_pro_read_execute(mixed $input = null): array
{
    return ['stand_in' => true];
}

/** The filter callback of the stand-in for Novamira Pro: adds its read abilities with their callbacks. */
function test_pro_read_filter(mixed $abilities): mixed
{
    if (!is_array($abilities)) {
        return $abilities;
    }
    foreach (TEST_PRO_READ_ABILITIES as $name) {
        $abilities[$name] = [
            'execute' => 'test_pro_read_execute',
            'permission' => 'novamira_permission_callback',
            'guidance' => 'stand-in for ' . $name . '.',
        ];
    }

    return $abilities;
}

/** Make the stand-in for Novamira Pro add its read abilities to read mode. */
function test_install_pro_stand_in(): void
{
    add_filter('novamira_read_mode_abilities', 'test_pro_read_filter');
}

/** Register a stand-in for one of the read abilities Novamira Pro adds, with the callbacks the filter names. */
function test_register_pro_stand_in(string $name, string $category = 'site-data'): void
{
    wp_register_ability($name, [
        'category' => $category,
        'permission_callback' => 'novamira_permission_callback',
        'execute_callback' => 'test_pro_read_execute',
        'meta' => [
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => true, 'destructive' => false],
        ],
    ]);
}
