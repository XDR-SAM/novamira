<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for ReadModeStateTest: php read-mode-state-runner.php <plugin root> <scenario>.
 */

if ($argc !== 3) {
    exit(2);
}

[, $root, $scenario] = $argv;

if (str_starts_with($scenario, 'cli-')) {
    define('WP_CLI', true);
}
define('NOVAMIRA_PRO_URL', 'https://novamira.ai/pro/');

require __DIR__ . '/read-mode-stubs.php';
require __DIR__ . '/options-wpdb.php';

$GLOBALS['actions'] = [];
$GLOBALS['cache_deletes'] = [];
$GLOBALS['pro_active'] = false;
$GLOBALS['ui_visible'] = true;

function wp_cache_get(string $key, string $group = ''): mixed
{
    $GLOBALS['calls'][] = ['wp_cache_get', $key];
    return false;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    $GLOBALS['cache_deletes'][] = [$key, $group];
    return true;
}

function has_action(string $hook, mixed $callback = false): bool
{
    foreach ($GLOBALS['hooks'] as [$name]) {
        if ($name === $hook) {
            return true;
        }
    }
    return false;
}

function do_action(string $hook, mixed ...$args): void
{
    $GLOBALS['actions'][] = [$hook, $args];
    foreach ($GLOBALS['hooks'] as [$name, $callback]) {
        if ($name === $hook) {
            $callback(...$args);
        }
    }
}

function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags($text));
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES);
}

function esc_url(string $url): string
{
    return $url;
}

function esc_html_e(string $text, string $domain = 'default'): void
{
    echo htmlspecialchars($text, ENT_QUOTES);
}

function wp_admin_notice(string $message, array $args = []): void
{
    echo '<div class="notice notice-' . ($args['type'] ?? 'info') . '">' . $message . '</div>';
}

function wp_nonce_field(string $action): void
{
    echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">';
}

function submit_button(string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true): void
{
    echo '<button type="submit" name="' . $name . '">' . $text . '</button>';
}

function wp_date(string $format, int $timestamp): string
{
    return gmdate('Y-m-d H:i', $timestamp);
}

function get_userdata(int $user_id): object|false
{
    return $user_id === 7 ? (object) ['user_login' => 'agency-admin'] : false;
}

function get_current_user_id(): int
{
    return 7;
}

function wp_die(string $message = '', mixed $title = '', array $args = []): never
{
    echo json_encode(['died' => $message, 'status' => $args['response'] ?? null]);
    exit(0);
}

function check_admin_referer(string $action): bool
{
    $GLOBALS['calls'][] = ['check_admin_referer', $action];
    return true;
}

function wp_safe_redirect(string $location): void
{
    echo json_encode(['redirect' => $location, 'state' => \Novamira\ReadMode\status()]);
    exit(0);
}

function add_query_arg(array $args, string $url): string
{
    return $url . '&' . http_build_query($args);
}

function novamira_render_admin_header(): void {}

function novamira_manage_capability(): string
{
    return 'manage_options';
}

function novamira_current_user_can_manage(): bool
{
    return $GLOBALS['can_manage'];
}

function novamira_pro_is_active(): bool
{
    return $GLOBALS['pro_active'];
}

/** Ghost Mode's answer to whether the current user sees Novamira. */
function novamira_admin_ui_visible(): bool
{
    return $GLOBALS['ui_visible'];
}

/** Records WP-CLI output. */
class WP_CLI
{
    /** @var list<array{string, string}> */
    public static array $out = [];

    public static function add_command(string $name, string $callable): void
    {
        self::$out[] = ['command', $name];
    }

    public static function line(string $message): void
    {
        self::$out[] = ['line', $message];
    }

    public static function success(string $message): void
    {
        self::$out[] = ['success', $message];
    }

    public static function error(string $message): void
    {
        self::$out[] = ['error', $message];
    }

    public static function confirm(string $message, array $assoc_args = []): void
    {
        self::$out[] = ['confirm', $message];
    }
}

require $root . '/includes/read-mode/policy.php';
require $root . '/includes/admin-page.php';
require $root . '/includes/features/admin.php';
require __DIR__ . '/read-mode-hub-stubs.php';

function novamira_sanitize_requested_ability_name(mixed $name): string
{
    return is_string($name) ? $name : '';
}

/** Only names with a namespace are valid, as in the real helper. */
function novamira_is_valid_ability_name(string $name): bool
{
    return str_contains($name, '/');
}

function novamira_update_ability_rules(array $rules): void
{
    $GLOBALS['rules_written'] = $rules;
}

function novamira_get_ability_rules(): array
{
    return [];
}

/** The Abilities Hub's check with read mode's part real and the feature part stood in for. */
function novamira_ability_can_be_managed_individually(string $name): bool
{
    return novamira_is_read_mode() ? novamira_read_mode_ability_is_choosable($name) : true;
}

function wp_send_json_error(mixed $data = null, ?int $status_code = null): never
{
    echo json_encode(['json_error' => $data, 'status' => $status_code]);
    exit(0);
}

function check_ajax_referer(string $action, mixed $query_arg = false, bool $stop = true): int|false
{
    return 1;
}

function disabled(mixed $disabled, mixed $current = true, bool $display = true): string
{
    $attribute = $disabled ? ' disabled="disabled"' : '';
    echo $attribute;
    return $attribute;
}

/** Render a callback and return its output. */
function test_output(callable $callback): string
{
    ob_start();
    $callback();
    return (string) ob_get_clean();
}

$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';

$result = match ($scenario) {
    'state-values' => (static function (): array {
        $values = [];
        $stored = [
            'absent' => null,
            'on' => ['schema_version' => 1, 'enabled' => true],
            'off' => ['schema_version' => 1, 'enabled' => false],
            // Earlier builds of read mode stored '1' and '0'.
            'legacy on' => '1',
            'legacy off' => '0',
            'legacy other string' => 'yes',
            // Serialized arrays that do not decode: truncated, and a corrupted length.
            'malformed truncated' => 'a:2:{s:7:"enabled";b:0;',
            'malformed length' => 'a:1:{s:99:"enabled";b:0;}',
            'serialized false' => 'b:0;',
            // A record that decodes, followed by data that is not part of it.
            'trailing data' => 'a:1:{s:7:"enabled";b:0;}junk',
            // A record that decodes but is not written the way serialize() writes it.
            'not canonical' => 'a:1:{s:7:"enabled";b:0;};',
            'no enabled flag' => ['schema_version' => 1],
            'enabled not boolean' => ['enabled' => 'yes'],
            'object' => 'O:8:"stdClass":1:{s:7:"enabled";b:0;}',
        ];
        foreach ($stored as $label => $value) {
            if ($value === null) {
                unset($GLOBALS['options']['novamira_read_mode']);
            } else {
                $GLOBALS['options']['novamira_read_mode'] = $value;
            }
            \Novamira\ReadMode\forget_state();
            $values[$label] = ['read_mode' => novamira_is_read_mode(), 'state' => \Novamira\ReadMode\status()['state']];
        }
        return $values;
    })(),
    'state-filters' => (static function (): array {
        test_read_mode(true);
        // Well-meaning code that makes get_option() report read mode as off.
        add_filter('pre_option_novamira_read_mode', static fn(): array => ['enabled' => false]);
        add_filter('option_novamira_read_mode', static fn(): array => ['enabled' => false]);
        add_filter('default_option_novamira_read_mode', static fn(): array => ['enabled' => false]);
        add_filter('alloptions', static fn(array $all): array => ['novamira_read_mode' => ['enabled' => false]] + $all);
        $GLOBALS['calls'] = [];
        $read_mode = novamira_is_read_mode();
        $state_queries = count(array_filter($GLOBALS['calls'], static fn(array $call): bool => $call[0] === 'state_query'));
        $consulted = array_values(array_filter(
            $GLOBALS['calls'],
            static fn(array $call): bool => in_array($call[0], ['get_option', 'wp_cache_get'], strict: true),
        ));
        // Read once per request.
        novamira_is_read_mode();
        novamira_is_read_mode();
        $state_queries_after = count(array_filter($GLOBALS['calls'], static fn(array $call): bool => $call[0] === 'state_query'));

        return [
            'read_mode' => $read_mode,
            // What WordPress' option API would report through the filters above.
            'filtered_option' => apply_filters('option_novamira_read_mode', apply_filters('pre_option_novamira_read_mode', false)),
            'consulted' => $consulted,
            'state_queries' => $state_queries,
            'state_queries_after_more_checks' => $state_queries_after,
            'query' => $GLOBALS['wpdb']->log[0] ?? null,
        ];
    })(),
    'state-database-error' => (static function (): array {
        test_read_mode(false);
        $GLOBALS['wpdb']->fail_with = 'Lost connection to MySQL server';
        $failing = novamira_is_read_mode();
        $GLOBALS['wpdb']->fail_with = '';
        // A failed read is not cached, so the next check reads again.
        return ['while_failing' => $failing, 'after_recovery' => novamira_is_read_mode()];
    })(),
    'state-sites' => (static function (): array {
        test_read_mode(true);
        $first = novamira_is_read_mode();
        // Another site of the network: its own options table, with no read-mode row.
        $GLOBALS['wpdb']->options = 'wp_2_options';
        unset($GLOBALS['options']['novamira_read_mode']);
        $second = novamira_is_read_mode();
        $GLOBALS['wpdb']->options = 'wp_options';

        return ['site_1' => $first, 'site_2' => $second, 'site_1_again' => novamira_is_read_mode()];
    })(),
    'api' => (static function (): array {
        $out = [];
        $out['absent'] = \Novamira\ReadMode\status();
        $out['disable_when_absent'] = test_export(\Novamira\ReadMode\disable(7, 'free-ui'));
        $out['writes_when_absent'] = count(array_filter($GLOBALS['calls'], static fn(array $call): bool => $call[0] === 'state_write'));
        $out['enable_from_free_ui'] = test_export(\Novamira\ReadMode\enable(7, 'free-ui'));
        $out['enable_unknown_source'] = test_export(\Novamira\ReadMode\enable(7, 'acme'));
        $out['still_off'] = novamira_is_read_mode();
        $out['enable_from_pro'] = test_export(\Novamira\ReadMode\enable(7, 'pro', pro_version: '2.4.0'));
        $out['on'] = novamira_is_read_mode();
        $stored = $GLOBALS['options']['novamira_read_mode'];
        $out['stored'] = ['enabled' => $stored['enabled'], 'schema_version' => $stored['schema_version'], 'updated_by' => $stored['updated_by'], 'source' => $stored['source'], 'pro_version' => $stored['pro_version'], 'recent' => abs(time() - $stored['updated_at']) < 60];
        $out['status_on'] = array_diff_key(\Novamira\ReadMode\status(), ['updated_at' => true]);
        $out['cache_deletes'] = $GLOBALS['cache_deletes'];
        $out['disable_unknown_source'] = test_export(\Novamira\ReadMode\disable(0, 'acme'));
        $out['disable_from_cli'] = test_export(\Novamira\ReadMode\disable(0, 'cli'));
        $out['off'] = novamira_is_read_mode();
        $out['status_off'] = array_diff_key(\Novamira\ReadMode\status(), ['updated_at' => true]);
        // A damaged record counts as on, and turning read mode off replaces it.
        $GLOBALS['options']['novamira_read_mode'] = 'garbage';
        \Novamira\ReadMode\forget_state();
        $out['damaged'] = \Novamira\ReadMode\status()['state'];
        $out['disable_damaged'] = test_export(\Novamira\ReadMode\disable(7, 'free-ui'));
        $out['repaired'] = \Novamira\ReadMode\status()['state'];
        // A write the database refuses is reported, not assumed.
        $GLOBALS['wpdb']->fail_with = 'INSERT command denied';
        $out['failed_write'] = test_export(\Novamira\ReadMode\enable(7, 'pro'));
        $GLOBALS['wpdb']->fail_with = '';
        $out['after_failed_write'] = novamira_is_read_mode();
        $out['constants'] = [
            'schema' => \Novamira\ReadMode\SCHEMA_VERSION,
            'runtime' => \Novamira\ReadMode\RUNTIME_VERSION,
            'option' => \Novamira\ReadMode\OPTION,
            'panel_hook' => \Novamira\ReadMode\PANEL_HOOK,
        ];
        return $out;
    })(),
    'option-change-hooks' => (static function (): array {
        test_read_mode(false);
        $before = novamira_is_read_mode();
        $GLOBALS['options']['novamira_read_mode'] = ['enabled' => true];
        // As update_option() does after it writes.
        \Novamira\ReadMode\forget_state_on_option_change('blogname');
        $unrelated = novamira_is_read_mode();
        \Novamira\ReadMode\forget_state_on_option_change('novamira_read_mode');
        return ['before' => $before, 'after_unrelated_option' => $unrelated, 'after_own_option' => novamira_is_read_mode()];
    })(),
    'query-cache' => [
        'state_query' => \Novamira\ReadMode\exclude_state_query_from_query_cache('', "SELECT option_value FROM wp_options WHERE option_name = 'novamira_read_mode' LIMIT 1"),
        'other_query' => \Novamira\ReadMode\exclude_state_query_from_query_cache('', 'SELECT * FROM wp_posts'),
        'other_reason_kept' => \Novamira\ReadMode\exclude_state_query_from_query_cache('modification query', 'UPDATE wp_posts SET x = 1'),
    ],
    'page-off', 'page-on', 'page-on-pro-panel', 'page-off-pro-outdated', 'page-on-pro-outdated', 'page-hidden', 'page-on-with-pro-abilities', 'page-unreadable' => (static function () use ($scenario): array {
        if (str_contains($scenario, '-on')) {
            test_read_mode(true);
            $GLOBALS['options']['novamira_read_mode']['updated_by'] = 7;
        }
        if ($scenario === 'page-on-pro-panel') {
            $GLOBALS['pro_active'] = true;
            add_action('novamira_read_mode_panel', static function (array $status): void {
                echo '<div id="pro-panel">Pro panel, state ' . $status['state'] . '</div>';
            });
        }
        if ($scenario === 'page-on-with-pro-abilities') {
            // Novamira Pro is active and licensed: its read abilities are allowed.
            $GLOBALS['pro_active'] = true;
            test_install_pro_stand_in();
            add_action('novamira_read_mode_panel', static function (array $status): void {});
        }
        if ($scenario === 'page-unreadable') {
            test_read_mode(true);
            $GLOBALS['options']['novamira_read_mode'] = 'garbage';
        }
        if ($scenario === 'page-off-pro-outdated' || $scenario === 'page-on-pro-outdated') {
            $GLOBALS['pro_active'] = true;
        }
        if ($scenario === 'page-hidden') {
            $GLOBALS['ui_visible'] = false;
        }
        $html = test_output(static fn() => \Novamira\ReadMode\render_page());

        return ['html' => $html, 'actions' => array_column($GLOBALS['actions'], 0)];
    })(),
    'hub-post-on', 'hub-post-off' => (static function () use ($scenario): never {
        if ($scenario === 'hub-post-on') {
            test_read_mode(true);
        }
        $_POST = [
            'novamira_ability_hub_action' => 'toggle_disabled',
            // A name without a namespace is refused as invalid when the request gets past the read-mode check.
            'ability_name' => 'no-namespace',
        ];
        novamira_handle_ability_hub_actions();
        exit(3);
    })(),
    'hub-group-on' => (static function (): never {
        test_read_mode(true);
        $_POST = [
            'novamira_ability_hub_action' => 'set_group',
            'group_action' => 'disable',
            'ability_names' => ['novamira/read-file'],
        ];
        novamira_handle_ability_hub_actions();
        exit(3);
    })(),
    'hub-ajax-on' => (static function (): never {
        test_read_mode(true);
        $_POST = ['ability_name' => 'novamira/read-file'];
        novamira_handle_ability_toggle_ajax();
        exit(3);
    })(),
    'features-post-on', 'features-post-off' => (static function () use ($scenario): never {
        if ($scenario === 'features-post-on') {
            test_read_mode(true);
        }
        $_POST = ['novamira_feature_action' => 'set', 'feature_id' => 'unknown-feature', 'active' => '1'];
        \Novamira\Features\Admin\handle_update();
        exit(3);
    })(),
    'hub-manageable-on' => (static function (): array {
        test_read_mode(true);
        add_filter('novamira_read_mode_abilities', static function (array $abilities): array {
            $abilities['novamira/elementor-get-page'] = [
                'execute' => 'test_execute',
                'permission' => 'test_permission',
                'optional' => true,
            ];

            return $abilities;
        });
        foreach (['novamira/read-file', 'novamira/elementor-get-page', 'acme/report'] as $name) {
            wp_register_ability($name, ['category' => 'test', 'description' => 'D', 'meta' => ['mcp' => ['public' => true]]]);
        }
        if (!function_exists('wp_get_ability_category')) {
            function wp_get_ability_category(string $slug): ?object
            {
                return null;
            }
        }

        $rules = ['novamira/elementor-get-page' => ['disabled' => true], 'novamira/read-file' => ['disabled' => true]];
        $rows = [];
        foreach (['novamira/read-file', 'novamira/elementor-get-page'] as $name) {
            $row = novamira_build_registered_ability_row(wp_get_ability($name), $rules);
            $rows[$name] = [
                'manageable' => $row['individually_manageable'],
                'infrastructure' => $row['infrastructure'],
                'disabled' => $row['disabled'],
                'label' => novamira_ability_row_management_label($row),
            ];
        }

        return [
            'can_manage' => [
                'required' => novamira_ability_can_be_managed_individually('novamira/read-file'),
                'chosen' => novamira_ability_can_be_managed_individually('novamira/elementor-get-page'),
                'not_allowed' => novamira_ability_can_be_managed_individually('acme/report'),
            ],
            'rows' => $rows,
            'toggled' => [
                'required' => novamira_toggle_ability_disabled_rule(['novamira/read-file' => ['disabled' => false]], 'novamira/read-file')['novamira/read-file']['disabled'],
                'chosen' => novamira_toggle_ability_disabled_rule(['novamira/elementor-get-page' => ['disabled' => false]], 'novamira/elementor-get-page')['novamira/elementor-get-page']['disabled'],
            ],
        ];
    })(),
    'hub-notice-on' => (static function (): array {
        test_read_mode(true);
        add_action(\Novamira\ReadMode\HUB_PANEL_HOOK, static function (array $status): void {
            echo '<div id="pro-notice">' . $status['state'] . '</div>';
        });

        return ['html' => test_output(static fn() => \Novamira\ReadMode\render_hub_notice())];
    })(),
    'features-notice-on', 'features-notice-off' => (static function () use ($scenario): array {
        if ($scenario === 'features-notice-on') {
            test_read_mode(true);
        }

        return [
            'notice' => test_output(static fn() => novamira_render_read_mode_management_notice('Features cannot be changed until Read Mode is off.')),
        ];
    })(),
    'menu-order', 'menu-order-without-hub', 'menu-order-without-read-mode' => (static function () use ($scenario): array {
        $slugs = [
            'novamira-connect',
            'novamira-abilities',
            'novamira-sandbox',
            'novamira-troubleshoot',
            'novamira-features',
            'novamira-ghost-mode',
            'novamira-read-mode',
        ];
        if ($scenario === 'menu-order-without-hub') {
            $slugs = array_values(array_diff($slugs, ['novamira-abilities']));
        }
        if ($scenario === 'menu-order-without-read-mode') {
            $slugs = array_values(array_diff($slugs, ['novamira-read-mode']));
        }
        $GLOBALS['submenu'] = ['novamira-connect' => array_map(
            static fn(string $slug): array => [$slug, 'manage_options', $slug, $slug],
            $slugs,
        ), 'tools.php' => [['Other', 'manage_options', 'other', 'Other']]];

        \Novamira\ReadMode\move_menu_entry();

        return [
            'order' => array_map(static fn(array $entry): string => $entry[2], $GLOBALS['submenu']['novamira-connect']),
            'other' => $GLOBALS['submenu']['tools.php'],
        ];
    })(),
    'disable-ok', 'disable-unconfirmed', 'disable-confirm-empty', 'disable-confirm-zero', 'disable-forbidden', 'disable-hidden' => (static function () use ($scenario): never {
        test_read_mode(true);
        $_POST = match ($scenario) {
            'disable-unconfirmed' => [],
            'disable-confirm-empty' => ['confirm' => ''],
            'disable-confirm-zero' => ['confirm' => '0'],
            default => ['confirm' => '1'],
        };
        if ($scenario === 'disable-forbidden') {
            $GLOBALS['can_manage'] = false;
        }
        if ($scenario === 'disable-hidden') {
            $GLOBALS['ui_visible'] = false;
        }
        // No Novamira Pro and no license: turning read mode off must still work.
        \Novamira\ReadMode\handle_disable();
    })(),
    'cli-on', 'cli-off' => (static function () use ($scenario): array {
        if ($scenario === 'cli-on') {
            test_read_mode(true, source: 'pro');
        }
        \Novamira\ReadMode\Cli\register();
        \Novamira\ReadMode\Cli\status_command();
        \Novamira\ReadMode\Cli\disable_command([], ['yes' => true]);
        \Novamira\ReadMode\Cli\status_command();
        return ['out' => WP_CLI::$out, 'stored_source' => $GLOBALS['options']['novamira_read_mode']['source'] ?? null];
    })(),
    'admin-bar-on', 'admin-bar-off' => (static function () use ($scenario): array {
        if ($scenario === 'admin-bar-on') {
            test_read_mode(true);
        }
        $node = [
            'id' => 'novamira-mcp-status',
            'title' => 'Novamira ON',
            'href' => 'https://example.test/wp-admin/admin.php?page=novamira-connect',
            'meta' => ['class' => 'novamira-mcp-on'],
        ];
        return ['node' => \Novamira\ReadMode\admin_bar_node($node)];
    })(),
    'notice-chat', 'notice-read-mode-page', 'notice-hub', 'notice-features', 'notice-other-screen', 'notice-hidden', 'notice-off' => (static function () use ($scenario): array {
        if ($scenario !== 'notice-off') {
            test_read_mode(true);
        }
        $_GET['page'] = match ($scenario) {
            'notice-read-mode-page' => 'novamira-read-mode',
            'notice-hub' => 'novamira-abilities',
            'notice-features' => 'novamira-features',
            'notice-other-screen' => 'acme-settings',
            default => 'novamira-chat',
        };
        if ($scenario === 'notice-hidden') {
            $GLOBALS['ui_visible'] = false;
        }
        return ['html' => test_output('novamira_render_read_mode_admin_notice')];
    })(),
    default => ['unknown_scenario' => $scenario],
};

echo json_encode($result, JSON_THROW_ON_ERROR);
