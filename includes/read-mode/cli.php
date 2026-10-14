<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * WP-CLI: `wp novamira read-mode status` and `wp novamira read-mode disable`. Turning read mode on is
 * Novamira Pro's command.
 */

namespace Novamira\ReadMode\Cli;

use WP_Error;

use function Novamira\ReadMode\describe_user;
use function Novamira\ReadMode\disable;
use function Novamira\ReadMode\status;

if (!defined('ABSPATH')) {
    exit();
}

function register(): void
{
    if (!defined('WP_CLI') || constant('WP_CLI') !== true || !class_exists('WP_CLI')) {
        return;
    }

    \WP_CLI::add_command('novamira read-mode status', __NAMESPACE__ . '\\status_command');
    \WP_CLI::add_command('novamira read-mode disable', __NAMESPACE__ . '\\disable_command');
}

/**
 * Show whether read mode is on for this site, and who changed it last.
 *
 * ## EXAMPLES
 *
 *     wp novamira read-mode status
 *     wp novamira read-mode status --url=https://example.com/subsite
 */
function status_command(): void
{
    $status = status();
    $labels = [
        'off' => __('off', domain: 'novamira'),
        'on' => __('on', domain: 'novamira'),
        'unreadable' => __('on (the saved setting is not valid)', domain: 'novamira'),
    ];

    \WP_CLI::line(sprintf(__('Read Mode: %s', domain: 'novamira'), $labels[$status['state']]));
    if ($status['updated_at'] > 0) {
        \WP_CLI::line(sprintf(
            __('Last change: %1$s by %2$s', domain: 'novamira'),
            (string) wp_date('Y-m-d H:i', $status['updated_at']),
            describe_user($status['updated_by']),
        ));
    }
}

/**
 * Turn read mode off for this site. AI agents can use every enabled ability again.
 *
 * ## OPTIONS
 *
 * [--yes]
 * : Skip the confirmation.
 *
 * ## EXAMPLES
 *
 *     wp novamira read-mode disable --yes
 *
 * @param list<string> $args
 * @param array<string, mixed> $assoc_args
 */
function disable_command(array $args, array $assoc_args): void
{
    \WP_CLI::confirm(
        __('Turn off Read Mode? Novamira will offer AI agents its write abilities again.', domain: 'novamira'),
        $assoc_args,
    );
    report(disable(user_id: 0, source: 'cli'), __('Read Mode is off.', domain: 'novamira'));
}

function report(?WP_Error $error, string $success): void
{
    if ($error instanceof WP_Error) {
        \WP_CLI::error($error->get_error_message());

        return;
    }
    \WP_CLI::success($success);
}
