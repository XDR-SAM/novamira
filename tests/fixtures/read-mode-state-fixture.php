<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Read-mode state for the test runners: records in a stand-in options table ($GLOBALS['options']), which
 * stand-in databases use to answer read mode's state query.
 */

/**
 * Store a read-mode record in the stand-in options table, as Novamira's state API does, and drop the
 * state read earlier in this request.
 */
function test_read_mode(bool $enabled, string $source = 'pro'): void
{
    $GLOBALS['options']['novamira_read_mode'] = [
        'schema_version' => 1,
        'enabled' => $enabled,
        'updated_at' => 1_700_000_000,
        'updated_by' => 1,
        'source' => $source,
        'pro_version' => '',
    ];
    if (function_exists('Novamira\\ReadMode\\forget_state')) {
        \Novamira\ReadMode\forget_state();
    }
}

/** Whether a statement is read mode's state query, which stand-in databases answer from $GLOBALS['options']. */
function test_is_state_query(string $sql): bool
{
    return str_contains($sql, "option_name = 'novamira_read_mode'");
}

/** The stand-in options table's answer to read mode's state query: the stored value, serialized as WordPress does. */
function test_state_answer(): ?string
{
    $GLOBALS['calls'][] = ['state_query'];
    if (!array_key_exists('novamira_read_mode', $GLOBALS['options'])) {
        return null;
    }

    /** @var mixed $value */
    $value = $GLOBALS['options']['novamira_read_mode'];

    return is_array($value) ? serialize($value) : (string) $value;
}

