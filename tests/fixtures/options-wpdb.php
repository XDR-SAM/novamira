<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * A stand-in wpdb for the read-mode runners that only needs the options table: it answers read mode's
 * state query from $GLOBALS['options'] and stores what the state API writes.
 */

require_once __DIR__ . '/read-mode-state-fixture.php';

// phpcs:ignore -- The stand-in must carry WordPress' class name to satisfy the state module's type.
class wpdb
{
    public string $options = 'wp_options';
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    /** An error every statement reports, to simulate a database that does not answer. */
    public string $fail_with = '';
    /** @var list<string> */
    public array $log = [];
    /** @var list<mixed> */
    private array $prepared_args = [];

    public function suppress_errors(bool $suppress = true): bool
    {
        return false;
    }

    public function get_var(string $query): ?string
    {
        $this->log[] = $query;
        $this->last_error = $this->fail_with;
        if ($this->fail_with !== '' || !test_is_state_query($query)) {
            return null;
        }

        return test_state_answer();
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $this->prepared_args = $args;
        return $query;
    }

    public function query(string $query): int|bool
    {
        $this->log[] = $query;
        $this->last_error = $this->fail_with;
        if ($this->fail_with !== '') {
            return false;
        }
        // The state API's write: INSERT INTO %i (...) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE ...
        if (str_starts_with($query, 'INSERT INTO %i') && ($this->prepared_args[1] ?? null) === 'novamira_read_mode') {
            $GLOBALS['options']['novamira_read_mode'] = unserialize((string) $this->prepared_args[2]);
            $GLOBALS['calls'][] = ['state_write', $this->prepared_args[0]];
            return 1;
        }

        return 0;
    }
}

$GLOBALS['wpdb'] = new wpdb();
