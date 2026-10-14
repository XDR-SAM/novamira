<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Child-process runner for ReadModeHubRulesTest: php read-mode-hub-rules-runner.php <plugin root> <scenario>.
 *
 * The Abilities Hub rules while read mode is on: turning a read ability back on after its rule took it out
 * of the registry, and refusing an ability the Hub turned off whatever door it comes through.
 */

if ($argc !== 3) {
    exit(2);
}

[, $root, $scenario] = $argv;

require __DIR__ . '/read-mode-stubs.php';
require __DIR__ . '/options-wpdb.php';
require $root . '/includes/helpers.php';
require $root . '/includes/read-mode/policy.php';
require $root . '/includes/admin-page.php';
require __DIR__ . '/read-mode-hub-stubs.php';

/** The AJAX endpoint answered: wp_send_json_* ends the request in WordPress, here it ends the call. */
final class Test_Json_Response extends RuntimeException
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $payload,
        public readonly ?int $status,
    ) {
        parent::__construct('json response');
    }
}

function did_action(string $hook): int
{
    return 0;
}

/** The implementation read mode trusts for novamira/read-file, as far as this runner executes it. */
function novamira_read_file(mixed $input = null): array
{
    return ['stand_in' => 'read-file'];
}

function check_ajax_referer(string $action, mixed $query_arg = false, bool $stop = true): int|false
{
    return 1;
}

function wp_send_json_success(mixed $data = null, ?int $status_code = null): never
{
    throw new Test_Json_Response(true, $data, $status_code);
}

function wp_send_json_error(mixed $data = null, ?int $status_code = null): never
{
    throw new Test_Json_Response(false, $data, $status_code);
}

/** Novamira's data abilities that Novamira Pro adds, and the one of them the administrator chooses. */
test_install_pro_stand_in();
add_filter('novamira_read_mode_abilities', static function (array $abilities): array {
    $abilities['novamira/get-object']['optional'] = true;
    return $abilities;
}, 20);
add_filter('wp_register_ability_args', 'novamira_read_mode_guard_ability_args', PHP_INT_MAX, 2);

$GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
$GLOBALS['options']['novamira_ai_abilities_domain'] = 'example.test';

/** An ability another plugin registers and declares read only. */
function test_register_declared(string $name = 'acme/report'): void
{
    wp_register_ability($name, [
        'category' => 'acme',
        'description' => 'About ' . $name . '.',
        'permission_callback' => static fn(): bool => true,
        'execute_callback' => static fn(): array => ['ran' => $name],
        'meta' => [
            'mcp' => ['public' => true, 'type' => 'tool'],
            'annotations' => ['readonly' => true],
        ],
    ]);
}

/** The abilities one request registers: a required one, an optional one of Novamira's, and a declared one. */
function test_register_request(): void
{
    $GLOBALS['abilities'] = [];
    $GLOBALS['unregistered'] = [];
    test_register('novamira/read-file', 'filesystem', ['mcp' => ['public' => true, 'type' => 'tool']]);
    test_register('novamira/get-object', 'site-data', ['mcp' => ['public' => true, 'type' => 'tool']]);
    test_register_declared();
}

/** One request that reaches the registry: the abilities register, then the policy runs. */
function test_boot_request(): void
{
    test_register_request();
    novamira_apply_ability_policy();
}

/** The Hub's switch for one ability, as the AJAX endpoint answers it. */
function test_toggle(string $name): array
{
    $_POST = ['ability_name' => $name];
    try {
        novamira_handle_ability_toggle_ajax();
    } catch (Test_Json_Response $response) {
        return ['success' => $response->success, 'payload' => $response->payload, 'status' => $response->status];
    }

    return ['unanswered' => true];
}

function test_rules(): array
{
    return $GLOBALS['options']['novamira_ability_rules'] ?? [];
}

/** What WordPress does when an ability object is executed: the permission callback first, then the callback. */
function test_run(WP_Ability $ability): array
{
    $permission = (new ReflectionProperty(WP_Ability::class, 'permission_callback'))->getValue($ability);
    if (!is_callable($permission) || $permission() !== true) {
        return ['refused' => true];
    }

    return ['ran' => $ability->execute()];
}

function test_usable(string $name): bool
{
    return wp_has_ability($name) && novamira_read_mode_unavailable_ability_error($name) === null;
}

$result = match ($scenario) {
    'toggle-on-again' => (static function (): array {
        test_read_mode(true);
        add_filter('novamira_read_mode_allow_declared_abilities', static fn(): bool => true);

        test_boot_request();
        $registered_first = wp_has_ability('acme/report');
        $turn_off = test_toggle('acme/report');

        test_boot_request();
        $registered_when_off = wp_has_ability('acme/report');
        $usable_when_off = test_usable('acme/report');
        $turn_on = test_toggle('acme/report');
        $rules_after_on = test_rules();

        test_boot_request();

        return [
            'registered_first' => $registered_first,
            'turn_off' => $turn_off,
            'registered_when_off' => $registered_when_off,
            'usable_when_off' => $usable_when_off,
            'turn_on' => $turn_on,
            'rules_after_on' => $rules_after_on,
            'registered_after_on' => wp_has_ability('acme/report'),
            'usable_after_on' => test_usable('acme/report'),
        ];
    })(),
    'toggle-on-again-chosen' => (static function (): array {
        test_read_mode(true);

        test_boot_request();
        $turn_off = test_toggle('novamira/get-object');
        test_boot_request();
        $registered_when_off = wp_has_ability('novamira/get-object');
        $turn_on = test_toggle('novamira/get-object');
        test_boot_request();

        return [
            'turn_off' => $turn_off,
            'registered_when_off' => $registered_when_off,
            'turn_on' => $turn_on,
            'rules_after_on' => test_rules(),
            'usable_after_on' => test_usable('novamira/get-object'),
        ];
    })(),
    'toggle-grants-nothing' => (static function (): array {
        test_read_mode(true);
        add_filter('novamira_read_mode_allow_declared_abilities', static fn(): bool => true);
        // A rule left on a name outside the list, and a rule on a name that is not registered.
        $GLOBALS['options']['novamira_ability_rules'] = [
            'novamira/execute-php' => ['disabled' => true],
            'acme/gone' => ['disabled' => true],
        ];
        test_boot_request();
        test_register('novamira/execute-php', 'code');

        $answers = [];
        foreach (['acme/never-ruled', 'novamira/read-file', 'novamira/execute-php', 'acme/gone'] as $name) {
            $answers[$name] = test_toggle($name);
        }
        $rules = test_rules();

        test_boot_request();
        test_register('novamira/execute-php', 'code');
        novamira_apply_read_mode_policy();

        return [
            'answers' => $answers,
            'rules' => $rules,
            'execute_php_registered' => wp_has_ability('novamira/execute-php'),
            'execute_php_error' => test_export(novamira_read_mode_unavailable_ability_error('novamira/execute-php')),
            'never_ruled_error' => test_export(novamira_read_mode_unavailable_ability_error('acme/never-ruled')),
            'gone_error' => test_export(novamira_read_mode_unavailable_ability_error('acme/gone')),
            'read_file_usable' => test_usable('novamira/read-file'),
        ];
    })(),
    'toggle-read-mode-off' => (static function (): array {
        // The switch outside read mode: any name is turned off and on again, registered or not.
        $off = test_toggle('acme/unregistered');
        $rules_after_off = test_rules();
        $on = test_toggle('acme/unregistered');

        return [
            'off' => $off,
            'rules_after_off' => $rules_after_off,
            'on' => $on,
            'rules_after_on' => test_rules(),
        ];
    })(),
    'kept-objects' => (static function (): array {
        test_read_mode(true);
        add_filter('novamira_read_mode_allow_declared_abilities', static fn(): bool => true);
        test_boot_request();
        $kept = [
            'novamira/read-file' => wp_get_ability('novamira/read-file'),
            'novamira/get-object' => wp_get_ability('novamira/get-object'),
            'acme/report' => wp_get_ability('acme/report'),
        ];
        $before = array_map('test_run', $kept);

        // The administrator turns all three off in the Hub while the objects are still held.
        $GLOBALS['options']['novamira_ability_rules'] = [
            'novamira/read-file' => ['disabled' => true],
            'novamira/get-object' => ['disabled' => true],
            'acme/report' => ['disabled' => true],
        ];

        return [
            'before' => $before,
            'after' => array_map('test_run', $kept),
            'refusals' => array_map(
                static fn(WP_Ability $ability): ?array => test_export(novamira_read_mode_ability_refusal($ability)),
                $kept,
            ),
            'pre_execute' => array_map(
                static fn(WP_Ability $ability): ?array => test_export(novamira_read_mode_pre_execute_ability(
                    null,
                    $ability->get_name(),
                    null,
                    $ability,
                )),
                $kept,
            ),
        ];
    })(),
    'late-registration' => (static function (): array {
        test_read_mode(true);
        add_filter('novamira_read_mode_allow_declared_abilities', static fn(): bool => true);
        $GLOBALS['options']['novamira_ability_rules'] = [
            'novamira/read-file' => ['disabled' => true],
            'novamira/get-object' => ['disabled' => true],
            'acme/report' => ['disabled' => true],
        ];
        test_boot_request();
        $after_policy = array_keys(wp_get_abilities());

        // Registered again once the policy has run, as a provider on a later hook does.
        test_register_request();
        $late = [
            'novamira/read-file' => wp_get_ability('novamira/read-file'),
            'novamira/get-object' => wp_get_ability('novamira/get-object'),
            'acme/report' => wp_get_ability('acme/report'),
        ];
        $run = array_map('test_run', $late);
        $refusals = array_map(
            static fn(WP_Ability $ability): ?array => test_export(novamira_read_mode_ability_refusal($ability)),
            $late,
        );
        $by_name = array_map(
            static fn(string $name): ?array => test_export(novamira_read_mode_unavailable_ability_error($name)),
            array_keys($late),
        );
        $execute_ability = array_map(
            static fn(string $name): ?array => test_export(novamira_read_mode_execute_ability_refusal($name)),
            array_keys($late),
        );
        // The next entry to the registry removes what the Hub turned off, the required ability excepted.
        novamira_apply_read_mode_policy();

        return [
            'after_policy' => $after_policy,
            'run' => $run,
            'refusals' => $refusals,
            'by_name' => array_combine(array_keys($late), $by_name),
            'execute_ability' => array_combine(array_keys($late), $execute_ability),
            'after_sweep' => array_keys(wp_get_abilities()),
        ];
    })(),
    'management-screen' => (static function (): array {
        // The Hub lists what it turned off, so the screen keeps those abilities registered.
        $GLOBALS['is_admin'] = true;
        $_GET['page'] = 'novamira-abilities';
        test_read_mode(true);
        add_filter('novamira_read_mode_allow_declared_abilities', static fn(): bool => true);
        $GLOBALS['options']['novamira_ability_rules'] = [
            'novamira/get-object' => ['disabled' => true],
            'acme/report' => ['disabled' => true],
        ];
        test_boot_request();

        return ['registered' => array_keys(wp_get_abilities())];
    })(),
    'read-mode-off' => (static function (): array {
        $GLOBALS['options']['novamira_ability_rules'] = [
            'novamira/get-object' => ['disabled' => true],
            'acme/report' => ['disabled' => true],
        ];
        test_register_request();
        $objects = [
            'novamira/get-object' => wp_get_ability('novamira/get-object'),
            'acme/report' => wp_get_ability('acme/report'),
        ];
        $GLOBALS['calls'] = [];

        $result = [
            'run' => array_map('test_run', $objects),
            'refusals' => array_map(
                static fn(WP_Ability $ability): ?array => test_export(novamira_read_mode_ability_refusal($ability)),
                $objects,
            ),
            'by_name' => test_export(novamira_read_mode_unavailable_ability_error('novamira/get-object')),
            'rules_read' => array_values(array_filter(
                $GLOBALS['calls'],
                static fn(array $call): bool => $call === ['get_option', 'novamira_ability_rules'],
            )),
        ];

        return $result;
    })(),
    'instructions' => (static function (): array {
        $listed = static fn(string $name): bool => array_filter(
            novamira_read_mode_instruction_lines(),
            static fn(string $line): bool => str_starts_with($line, '- ' . $name . ':'),
        ) !== [];
        $names = ['novamira/read-file', 'novamira/get-object'];
        $snapshot = static fn(): array => array_combine($names, array_map($listed, $names));

        test_read_mode(true);
        $on = $snapshot();
        $GLOBALS['options']['novamira_ability_rules'] = [
            'novamira/read-file' => ['disabled' => true],
            'novamira/get-object' => ['disabled' => true],
        ];
        $turned_off = $snapshot();
        $GLOBALS['options']['novamira_ability_rules'] = [];
        $turned_on_again = $snapshot();

        // Read mode off: the lines are not read, and no rule changes them.
        test_read_mode(false);
        $GLOBALS['options']['novamira_ability_rules'] = ['novamira/get-object' => ['disabled' => true]];
        $GLOBALS['calls'] = [];
        $off = $snapshot();

        return [
            'on' => $on,
            'turned_off' => $turned_off,
            'turned_on_again' => $turned_on_again,
            'read_mode_off' => $off,
            'rules_read_when_off' => array_values(array_filter(
                $GLOBALS['calls'],
                static fn(array $call): bool => $call === ['get_option', 'novamira_ability_rules'],
            )),
        ];
    })(),
    default => ['unknown_scenario' => $scenario],
};

echo json_encode($result, JSON_THROW_ON_ERROR);
