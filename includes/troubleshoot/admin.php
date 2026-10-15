<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Troubleshoot\Admin;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'novamira-troubleshoot';

/**
 * Register the standalone Troubleshoot page. The diagnostics are not a step in the connection
 * flow, so they live in their own submenu rather than inside the Connect page; the Connect page
 * links here when something is not working.
 */
function register_menu(): void
{
    $hook = add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Troubleshoot', domain: 'novamira'),
        menu_title: __('Troubleshoot', domain: 'novamira'),
        capability: \novamira_manage_capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );

    // The reset POST redirects, so it has to run before any admin HTML is sent. The page callback
    // fires after the admin header, where wp_redirect() is already a no-op.
    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, __NAMESPACE__ . '\\handle_load');
    }
}

function page_url(): string
{
    return admin_url('admin.php?page=' . PAGE_SLUG);
}

/**
 * Handle the "Reset registration limits" POST. The limits exist to keep anonymous registration from
 * being flooded, and an administrator reaching this page is on the other side of that door: their
 * own session is never rate limited, so they can always clear a lockout their AI client cannot.
 */
function handle_load(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (($_POST['novamira_action'] ?? '') !== 'reset_registration_limits') {
        return;
    }
    if (!is_user_logged_in() || !\novamira_current_user_can_manage()) {
        return;
    }
    check_admin_referer('novamira_troubleshoot_reset_limits');

    // The OAuth endpoints are only registered while AI Abilities are on, and with them off there is
    // no limit to clear (see Novamira\OAuth\boot).
    if (!function_exists('Novamira\\OAuth\\ClientValidation\\reset_registration_limits')) {
        return;
    }
    $removed = \Novamira\OAuth\ClientValidation\reset_registration_limits();

    wp_redirect(add_query_arg(['limits_reset' => '1', 'removed' => $removed], page_url()));
    exit();
}

/**
 * Best guess at which connection method to diagnose, since this page is reached outside the
 * Connect flow where the method was known. The strongest signal is the last authenticated MCP
 * request, which is recorded per method; failing that, the presence of MCP Application Passwords
 * or registered OAuth clients. Empty string means "unknown", and the panel then checks both.
 *
 * @return 'oauth'|'password'|''
 */
function detect_connection_method(): string
{
    // @mago-expect analysis:mixed-assignment
    $last = get_option('novamira_mcp_last_request', default_value: []);
    if (is_array($last)) {
        $oauth = is_int($last['oauth'] ?? null) ? $last['oauth'] : 0;
        $password = is_int($last['password'] ?? null) ? $last['password'] : 0;
        if ($oauth > 0 || $password > 0) {
            return $oauth >= $password ? 'oauth' : 'password';
        }
    }

    if (function_exists('novamira_get_mcp_passwords') && \novamira_get_mcp_passwords() !== []) {
        return 'password';
    }

    if (
        // A fresh install has no OAuth tables until abilities are enabled. Existing storage must
        // still be checked when abilities are off, since its connections may need troubleshooting.
        get_option('novamira_oauth_schema_version', default_value: false) !== false
        && function_exists('Novamira\\OAuth\\ClientValidation\\active_client_count')
        && \Novamira\OAuth\ClientValidation\active_client_count() > 0
    ) {
        return 'oauth';
    }

    return '';
}

/** Confirmation shown after handle_load() redirects back, so the reset reports what it freed. */
function render_reset_notice(): void
{
    $raw = $_GET['limits_reset'] ?? null;
    if (!is_string($raw) || $raw !== '1') {
        return;
    }
    $removed = (int) ($_GET['removed'] ?? 0);
    echo '<div class="notice notice-success is-dismissible"><p>';
    echo
        esc_html(sprintf(
            /* translators: %d: number of incomplete registrations that were removed */
            _n(
                single: 'Registration limits reset. %d incomplete registration was removed. Connect your AI client once now: each further attempt starts filling the limits again.',
                plural: 'Registration limits reset. %d incomplete registrations were removed. Connect your AI client once now: each further attempt starts filling the limits again.',
                number: $removed,
                domain: 'novamira',
            ),
            $removed,
        ))
    ;
    echo '</p></div>';
}

function render_page(): void
{
    if (!\novamira_current_user_can_manage()) {
        return;
    }
    $method = detect_connection_method();
    if (function_exists('novamira_render_admin_header')) {
        \novamira_render_admin_header();
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Troubleshoot the Connection', domain: 'novamira'); ?></h1>
        <hr class="wp-header-end">
        <p class="description"><?php esc_html_e(
            'Run these checks when an AI client cannot connect. They probe this site the way a client does and point at what to fix.',
            domain: 'novamira',
        ); ?></p>
        <?php render_reset_notice(); ?>
        <?php \Novamira\Troubleshoot\UI\render_panel(
            context: 'troubleshoot',
            method: $method,
            with_method_picker: true,
        ); ?>
    </div>
    <?php
}
