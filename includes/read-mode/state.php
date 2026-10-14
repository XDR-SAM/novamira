<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The read-mode state: schema, authoritative read, and the API that turns read mode on and off.
 *
 * Novamira owns the state and everything read mode enforces; Novamira Pro owns turning it on (behind its
 * license) through enable(). Turning it off needs no license: the Read Mode page and the
 * `wp novamira read-mode disable` command call disable(). Read mode stays on when Pro is deactivated or
 * its license expires.
 *
 * The state is per site. It is read straight from the site's options table through $wpdb, never through
 * get_option(), so option filters (pre_option_*, option_*, default_option_*, alloptions) and the object
 * cache cannot make it read as off. A value that cannot be read counts as on. There is deliberately no
 * filter on the state.
 */

namespace Novamira\ReadMode;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

const OPTION = 'novamira_read_mode';

/** The version of the stored record. */
const SCHEMA_VERSION = 1;

/**
 * The version of this API (enable(), disable(), status(), the panel hook, the novamira_read_mode_abilities
 * filter); Pro checks it for compatibility.
 */
const RUNTIME_VERSION = 3;

/** Who changed the state: Novamira Pro, WP-CLI, or Novamira's own Read Mode page. */
const SOURCES = ['pro', 'cli', 'free-ui'];

/** The sources that can turn read mode on. Novamira itself offers no way to turn it on. */
const ENABLE_SOURCES = ['pro', 'cli'];

/** The statement that reads the state; the option name is a constant, so it needs no escaping. */
const STATE_QUERY = "SELECT option_value FROM %s WHERE option_name = 'novamira_read_mode' LIMIT 1";

// @mago-expect lint:file-name -- this file is the state module; the per-request cache lives beside it.
final class StateCache
{
    /** @var array<string, array{state: 'absent'|'ok'|'unreadable', enabled: bool, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string}> */
    public static array $by_table = [];

    /** Whether every check reads the database, as in a long-running MCP STDIO process. */
    public static bool $fresh = false;

    /** Whether read mode was active when the watch started, or null when nothing watches for a change. */
    public static ?bool $watched_active = null;

    /** Called once when a fresh read finds read mode active or not, unlike when the watch started. */
    public static ?\Closure $on_change = null;
}

/** Read the state from the database again on the next check. */
function forget_state(): void
{
    StateCache::$by_table = [];
}

/**
 * From now on, read the state from the database on every check. For a process that outlives a request,
 * such as the MCP STDIO server, where a change made by another process must apply to the next call.
 */
function read_fresh_from_now_on(): void
{
    StateCache::$fresh = true;
    forget_state();
}

/**
 * Call $on_change once, with whether read mode is now active, when a check finds that read mode turned on
 * or off since now. Only fresh reads notice (see read_fresh_from_now_on()). For the MCP STDIO server,
 * which ends its session instead of serving on in a mix of both modes.
 */
function watch_for_change(callable $on_change): void
{
    StateCache::$watched_active = is_active(read_state());
    StateCache::$on_change = \Closure::fromCallable($on_change);
}

/**
 * @param array{state: 'absent'|'ok'|'unreadable', enabled: bool, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string} $state
 */
function notice_change(array $state): void
{
    $on_change = StateCache::$on_change;
    if (
        StateCache::$watched_active === null
        || $on_change === null
        || is_active($state) === StateCache::$watched_active
    ) {
        return;
    }

    StateCache::$watched_active = null;
    StateCache::$on_change = null;
    $on_change(is_active($state));
}

/**
 * The state from a stored value: false when there is none, an array record, the '0' or '1' earlier
 * builds stored, or anything else (which cannot be read, and so counts as on).
 *
 * @return array{state: 'absent'|'ok'|'unreadable', enabled: bool, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string}
 */
function normalize(mixed $raw): array
{
    $state = [
        'state' => 'absent',
        'enabled' => false,
        'schema_version' => 0,
        'updated_at' => 0,
        'updated_by' => 0,
        'source' => '',
        'pro_version' => '',
    ];
    if ($raw === false) {
        return $state;
    }
    if ($raw === '0' || $raw === '1') {
        $state['state'] = 'ok';
        $state['enabled'] = $raw === '1';
        $state['source'] = 'legacy';

        return $state;
    }
    if (!is_array($raw) || !is_bool($raw['enabled'] ?? null)) {
        $state['state'] = 'unreadable';

        return $state;
    }

    return record_state($raw, $raw['enabled']);
}

/**
 * The state from a stored record whose enabled flag was read.
 *
 * @param array<array-key, mixed> $raw
 * @return array{state: 'ok', enabled: bool, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string}
 */
function record_state(array $raw, bool $enabled): array
{
    return [
        'state' => 'ok',
        'enabled' => $enabled,
        'schema_version' => is_int($raw['schema_version'] ?? null) ? $raw['schema_version'] : 0,
        'updated_at' => is_int($raw['updated_at'] ?? null) ? $raw['updated_at'] : 0,
        'updated_by' => is_int($raw['updated_by'] ?? null) ? $raw['updated_by'] : 0,
        'source' => is_string($raw['source'] ?? null) ? $raw['source'] : '',
        'pro_version' => is_string($raw['pro_version'] ?? null) ? $raw['pro_version'] : '',
    ];
}

/** WordPress' database handle, or null before it exists. */
function database(): ?\wpdb
{
    // @mago-expect lint:no-global -- $wpdb is WordPress' database handle.
    global $wpdb;

    return $wpdb instanceof \wpdb ? $wpdb : null;
}

/**
 * The stored value straight from the options table: false when there is no row, null when the database
 * did not answer, and otherwise decode()'s result, which is never false or null.
 */
function read_raw(\wpdb $db): mixed
{
    $suppressed = $db->suppress_errors(true);
    $value = $db->get_var(sprintf(STATE_QUERY, $db->options));
    $error = $db->last_error;
    $db->suppress_errors($suppressed);

    if ($error !== '') {
        return null;
    }
    if (!is_string($value)) {
        return false;
    }

    return decode($value);
}

/**
 * A stored value decoded: the record array, or the stored string itself when it is not a serialized
 * array or does not decode exactly (normalize() reads such a string as the legacy '0' or '1', or as
 * unreadable). A value decodes exactly only when unserialize() raises nothing and serializing the result
 * gives back the stored value byte for byte, so trailing or otherwise extra data is never accepted, on
 * any PHP version. Objects are never restored, and a malformed value raises no warning.
 */
function decode(string $value): array|string
{
    if (!str_starts_with($value, 'a:')) {
        return $value;
    }

    $raised = false;
    set_error_handler(static function () use (&$raised): bool {
        $raised = true;
        return true;
    });
    try {
        // @mago-expect analysis:mixed-assignment -- the decoded value is checked below.
        $decoded = unserialize($value, ['allowed_classes' => false]);
    } finally {
        restore_error_handler();
    }

    return !$raised && is_array($decoded) && serialize($decoded) === $value ? $decoded : $value;
}

/**
 * The state of the current site, read once per request (and again after a change made through this API
 * or WordPress' option functions), or on every check after read_fresh_from_now_on(). A database that
 * does not answer is not cached, and counts as on.
 *
 * @return array{state: 'absent'|'ok'|'unreadable', enabled: bool, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string}
 */
function read_state(): array
{
    $db = database();
    if ($db === null) {
        return normalize(null);
    }

    // Keyed by the options table, so each site of a network keeps its own state.
    $table = $db->options;
    if (!StateCache::$fresh && array_key_exists($table, StateCache::$by_table)) {
        return StateCache::$by_table[$table];
    }

    // @mago-expect analysis:mixed-assignment -- the stored value is checked by normalize().
    $raw = read_raw($db);
    $state = normalize($raw);
    if ($raw !== null) {
        StateCache::$by_table[$table] = $state;
    }
    notice_change($state);

    return $state;
}

/**
 * Whether a state puts the site in read mode: on, or unreadable.
 *
 * @param array{state: 'absent'|'ok'|'unreadable', enabled: bool, schema_version: int, updated_at: int, updated_by: int, source: string, pro_version: string} $state
 */
function is_active(array $state): bool
{
    return $state['state'] === 'unreadable' || $state['state'] === 'ok' && $state['enabled'];
}

/**
 * The read-mode status of the current site, for the Read Mode page, WP-CLI, and Novamira Pro.
 *
 * @return array{state: 'off'|'on'|'unreadable', active: bool, updated_at: int, updated_by: int, source: string, pro_version: string, schema_version: int}
 */
function status(): array
{
    $state = read_state();

    return [
        'state' => match (true) {
            $state['state'] === 'unreadable' => 'unreadable',
            $state['enabled'] => 'on',
            default => 'off',
        },
        'active' => is_active($state),
        'updated_at' => $state['updated_at'],
        'updated_by' => $state['updated_by'],
        'source' => $state['source'],
        'pro_version' => $state['pro_version'],
        'schema_version' => $state['schema_version'],
    ];
}

/**
 * Turn read mode on for the current site. For Novamira Pro (source 'pro') and its WP-CLI command
 * (source 'cli'); the caller checks the capability, the nonce, and the license.
 */
function enable(int $user_id, string $source, string $pro_version = ''): ?WP_Error
{
    if (!in_array($source, ENABLE_SOURCES, strict: true)) {
        return new WP_Error('novamira_read_mode_invalid_source', __(
            'Read mode can only be turned on by Novamira Pro.',
            domain: 'novamira',
        ));
    }

    return write(record(true, $user_id, $source, $pro_version));
}

/**
 * Turn read mode off for the current site. Needs no license; the caller checks the capability and nonce.
 * An unreadable state is overwritten, so this also recovers a damaged record.
 */
function disable(int $user_id, string $source): ?WP_Error
{
    if (!in_array($source, SOURCES, strict: true)) {
        return new WP_Error('novamira_read_mode_invalid_source', __('Unknown read mode source.', domain: 'novamira'));
    }

    $state = read_state();
    if ($state['state'] === 'absent') {
        return null;
    }

    return write(record(false, $user_id, $source, $state['pro_version']));
}

/**
 * @return array{schema_version: int, enabled: bool, updated_at: int, updated_by: int, source: string, pro_version: string}
 */
function record(bool $enabled, int $user_id, string $source, string $pro_version): array
{
    return [
        'schema_version' => SCHEMA_VERSION,
        'enabled' => $enabled,
        'updated_at' => time(),
        'updated_by' => $user_id,
        'source' => $source,
        'pro_version' => $pro_version,
    ];
}

/**
 * Store a record straight in the options table, not autoloaded, then confirm it by reading it back.
 *
 * Written through $wpdb rather than update_option(), which compares against the filtered current value
 * and could skip the write; WordPress' option caches are cleared so get_option() sees the change too.
 *
 * @param array{schema_version: int, enabled: bool, updated_at: int, updated_by: int, source: string, pro_version: string} $record
 */
function write(array $record): ?WP_Error
{
    $db = database();
    $error = '';
    if ($db !== null) {
        $value = serialize($record);
        $table = $db->options;
        $statement = $db->prepare(
            "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = %s, autoload = 'off'",
            $table,
            OPTION,
            $value,
            $value,
        );
        if (is_string($statement)) {
            $suppressed = $db->suppress_errors(true);
            $db->query($statement);
            $error = $db->last_error;
            $db->suppress_errors($suppressed);
        }
    }

    forget_state();
    forget_option_caches();

    $written = read_state();
    if ($written['state'] !== 'ok' || $written['enabled'] !== $record['enabled']) {
        return new WP_Error('novamira_read_mode_write_failed', sprintf(
            /* translators: %s: the database error, if any */
            __('The read mode setting could not be saved. %s', domain: 'novamira'),
            $error,
        ));
    }

    return null;
}

/**
 * Clear WordPress' cached copies of the option, so get_option() reads the stored value again. The
 * cached lists of missing and autoloaded options are rebuilt by WordPress on their next use.
 */
function forget_option_caches(): void
{
    if (!function_exists('wp_cache_delete')) {
        return;
    }

    foreach ([OPTION, 'notoptions', 'alloptions'] as $key) {
        wp_cache_delete($key, group: 'options');
    }
}

/** Forget the cached state when the option changes through WordPress' option functions. */
function forget_state_on_option_change(string $option): void
{
    if ($option === OPTION) {
        forget_state();
    }
}

/**
 * Keep W3 Total Cache's database cache from caching the state query, whose answer must always come
 * from the database.
 */
function exclude_state_query_from_query_cache(mixed $reject_reason, mixed $query): mixed
{
    if (!is_string($query) || !str_contains($query, "option_name = 'novamira_read_mode'")) {
        return $reject_reason;
    }

    return 'novamira read mode state';
}

/** Register the hooks that keep the state current. */
function register_state_hooks(): void
{
    add_action('added_option', __NAMESPACE__ . '\\forget_state_on_option_change');
    add_action('updated_option', __NAMESPACE__ . '\\forget_state_on_option_change');
    add_action('deleted_option', __NAMESPACE__ . '\\forget_state_on_option_change');
    add_filter(
        'w3tc_dbcache_can_cache_sql',
        __NAMESPACE__ . '\\exclude_state_query_from_query_cache',
        priority: PHP_INT_MAX,
        accepted_args: 2,
    );
}
