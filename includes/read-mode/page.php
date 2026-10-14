<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * The Read Mode page (Novamira → Read Mode): status, the form that turns read mode off, and the panel
 * Novamira Pro adds to turn it on. Novamira itself offers no way to turn read mode on.
 */

namespace Novamira\ReadMode;

use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

const PAGE_SLUG = 'novamira-read-mode';

const DISABLE_ACTION = 'novamira_read_mode_disable';

/** The action Novamira Pro hooks to render its panel that turns read mode on. Receives status(). */
const PANEL_HOOK = 'novamira_read_mode_panel';

/**
 * The action Novamira Pro hooks to render, in the Abilities Hub while read mode is on, the choice of
 * which further read abilities are allowed. Receives status().
 */
const HUB_PANEL_HOOK = 'novamira_read_mode_hub_panel';

function page_url(): string
{
    return admin_url('admin.php?page=' . PAGE_SLUG);
}

function enqueue_page_assets(string $hook): void
{
    if ($hook !== 'novamira_page_' . PAGE_SLUG) {
        return;
    }

    $path = __DIR__ . '/assets/page.css';
    $version = defined('WP_DEBUG') && constant('WP_DEBUG') === true && is_file($path)
        ? (string) filemtime($path)
        : NOVAMIRA_VERSION;

    wp_enqueue_style(
        'novamira-read-mode',
        (string) NOVAMIRA_PLUGIN_URL . 'includes/read-mode/assets/page.css',
        [],
        $version,
    );
}

/** Whether the current user sees Novamira's admin screens, respecting Ghost Mode. */
function admin_ui_visible(): bool
{
    return (
        \novamira_current_user_can_manage()
        && (!function_exists('novamira_admin_ui_visible') || \novamira_admin_ui_visible())
    );
}

/** Registered at priority 91, directly after Ghost Mode (90). */
function register_menu(): void
{
    add_submenu_page(
        parent_slug: 'novamira-connect',
        page_title: __('Read Mode', domain: 'novamira'),
        menu_title: __('Read Mode', domain: 'novamira'),
        capability: \novamira_manage_capability(),
        menu_slug: PAGE_SLUG,
        callback: __NAMESPACE__ . '\\render_page',
    );
}

/**
 * Put the Read Mode entry directly after the Abilities Hub, whatever order the pages registered in: both
 * decide what agents can use. Runs after every other menu change (the Design page does the same for its
 * own entry), and leaves the entry where it is when the Abilities Hub is not there.
 */
function move_menu_entry(): void
{
    // @mago-expect lint:no-global -- $submenu is WordPress' admin submenu.
    global $submenu;
    if (!is_array($submenu ?? null) || !is_array($submenu['novamira-connect'] ?? null)) {
        return;
    }

    /** @var array<array-key, array<array-key, mixed>> $entries */
    $entries = $submenu['novamira-connect'];
    $own = null;
    $others = [];
    foreach ($entries as $entry) {
        if (($entry[2] ?? null) === PAGE_SLUG) {
            $own = $entry;
            continue;
        }
        $others[] = $entry;
    }
    if ($own === null) {
        return;
    }

    $reordered = [];
    $placed = false;
    foreach ($others as $entry) {
        $reordered[] = $entry;
        if (!$placed && ($entry[2] ?? null) === 'novamira-abilities') {
            $reordered[] = $own;
            $placed = true;
        }
    }
    if (!$placed) {
        $reordered[] = $own;
    }
    $submenu['novamira-connect'] = $reordered;
}

function render_page(): void
{
    if (!admin_ui_visible()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', domain: 'default'), args: [
            'response' => 403,
        ]);
    }

    $status = status();
    \novamira_render_admin_header();
    ?>
    <div class="wrap novamira-read-mode-page">
        <h1 class="wp-heading-inline"><?php esc_html_e('Read Mode', domain: 'novamira'); ?></h1>
        <hr class="wp-header-end">
        <p class="novamira-rm-lead"><?php esc_html_e(
            'Read Mode is production ready. Let AI agents look at this site without Novamira\'s write abilities.',
            domain: 'novamira',
        ); ?></p>
        <?php render_disabled_notice(); ?>
        <?php render_limits(); ?>
        <?php render_status($status); ?>
        <?php render_panel($status); ?>
        <?php render_turn_off($status); ?>
    </div>
    <?php
}

/**
 * What the Abilities Hub says at its top while read mode is on: only read abilities are listed, single ones
 * can be turned off and on, and the groups of read abilities are chosen on the Read Mode page. Novamira Pro
 * adds what is missing, such as an inactive license.
 */
function render_hub_notice(): void
{
    $status = status();
    ?>
    <div class="notice notice-info inline"><p><?php echo
        sprintf(
            '%s <a href="%s">%s</a>',
            esc_html__(
                'Read Mode is on, so only read abilities are listed. Turn single abilities on or off here; you choose the groups of read abilities on the Read Mode page. An MCP session that is already open over STDIO needs a restart to see a change.',
                domain: 'novamira',
            ),
            esc_url(page_url()),
            esc_html__('Go to Read Mode', domain: 'novamira'),
        )
    ; ?></p></div>
    <?php do_action(HUB_PANEL_HOOK, $status); ?>
    <?php
}

/** What Read Mode covers and what it is not, at the top of the page. */
function render_limits(): void
{ ?>
    <div class="novamira-rm-security" role="note">
        <p><strong><?php esc_html_e('Read Mode applies to Novamira.', domain: 'novamira'); ?></strong>
            <?php esc_html_e(
                'It controls what Novamira offers the agent. It does not turn off the other tools of your AI client: a browser or another MCP server can still make changes if you leave them enabled. Read Mode is not a security feature: the agent connects with an administrator\'s credential and can read everything an administrator can read.',
                domain: 'novamira',
            ); ?></p>
        <p><strong><?php esc_html_e('Some things can still change.', domain: 'novamira'); ?></strong>
            <?php esc_html_e(
                'A read ability may change some of the site\'s temporary data as part of reading, for example by clearing an expired transient, rebuilding a cache or a generated file, or updating a counter. Other plugins can also react to a read through their own hooks, for example by emptying a page cache.',
                domain: 'novamira',
            ); ?></p>
    </div>
    <?php }

function render_disabled_notice(): void
{
    if (($_GET['novamira_read_mode'] ?? null) !== 'disabled') {
        return;
    }
    wp_admin_notice(
        esc_html__('Read Mode is off. AI agents can use every enabled ability again.', domain: 'novamira'),
        [
            'type' => 'success',
        ],
    );
}

/**
 * Whether any read ability beyond the ones Novamira ships is allowed: Novamira Pro adds its own when it is
 * active and its license allows them. Without them Read Mode is on but not working as intended.
 */
function has_extra_read_abilities(): bool
{
    return \novamira_read_mode_has_extra_abilities();
}

/**
 * The status card, while read mode is on: its state, when it changed, and what needs attention.
 *
 * @param array{state: 'off'|'on'|'unreadable', active: bool, updated_at: int, updated_by: int, source: string, pro_version: string, schema_version: int} $status
 */
function render_status(array $status): void
{
    if (!$status['active']) {
        return;
    }

    $alert = $status['state'] === 'unreadable' || !has_extra_read_abilities();
    ?>
    <div class="novamira-rm-card">
        <div class="novamira-rm-status-head">
            <h2><?php esc_html_e('Status', domain: 'novamira'); ?></h2>
            <span class="novamira-rm-pill <?php echo $alert ? 'is-alert' : 'is-on'; ?>"><?php echo
                $alert ? esc_html__('Needs attention', domain: 'novamira') : esc_html__('On', domain: 'novamira')
            ; ?></span>
        </div>
        <?php if ($status['updated_at'] > 0): ?>
            <p><?php echo
                esc_html(sprintf(
                    /* translators: 1: date and time, 2: user */
                    __('Last changed %1$s by %2$s.', domain: 'novamira'),
                    (string) wp_date(
                        (string) get_option('date_format') . ' ' . (string) get_option('time_format'),
                        $status['updated_at'],
                    ),
                    describe_user($status['updated_by']),
                ))
            ; ?></p>
        <?php endif; ?>
        <?php if ($status['state'] === 'unreadable'): ?>
            <p class="novamira-rm-warning is-error"><?php esc_html_e(
                'The saved Read Mode setting is not valid, so Read Mode stays on. Turn it off to reset it.',
                domain: 'novamira',
            ); ?></p>
        <?php endif; ?>
        <?php render_missing_pro_warning(); ?>
        <?php if (has_action(HUB_PANEL_HOOK) !== false): ?>
            <p><?php echo
                sprintf(
                    '%s <a href="%s">%s</a>.',
                    esc_html__('You can turn single read abilities on or off in the', domain: 'novamira'),
                    esc_url(admin_url('admin.php?page=novamira-abilities')),
                    esc_html__('Abilities Hub', domain: 'novamira'),
                )
            ; ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Why Read Mode is not working as intended, when Novamira Pro shows no panel of its own to say it: Pro is
 * not active, or too old to provide its read abilities.
 */
function render_missing_pro_warning(): void
{
    if (has_action(PANEL_HOOK) || has_extra_read_abilities()) {
        return;
    }
    ?>
    <p class="novamira-rm-warning"><?php echo
        esc_html(
            \novamira_pro_is_active()
                ? __(
                    'Read Mode is on but not working as intended: this version of Novamira Pro does not provide its read abilities. Novamira keeps its write abilities turned off. Update Novamira Pro, or turn Read Mode off.',
                    domain: 'novamira',
                )
                : __(
                    'Read Mode is on but not working as intended: it needs Novamira Pro to be active, and its read abilities are not available. Novamira keeps its write abilities turned off. Activate Novamira Pro, or turn Read Mode off.',
                    domain: 'novamira',
                ),
        )
    ; ?></p>
    <?php
}

/**
 * Novamira Pro's panel: the form that turns read mode on, and what is wrong while it is on without a
 * license. Without Pro's panel, what Read Mode is and that it needs Novamira Pro.
 *
 * @param array{state: 'off'|'on'|'unreadable', active: bool, updated_at: int, updated_by: int, source: string, pro_version: string, schema_version: int} $status
 */
function render_panel(array $status): void
{
    if (has_action(PANEL_HOOK)) {
        do_action(PANEL_HOOK, $status);

        return;
    }
    if ($status['active']) {
        return;
    }

    render_presentation();
    render_pro_card();
}

/** What the agent can and cannot use in read mode, in plain words, for a site that has not turned it on. */
function render_presentation(): void
{ ?>
    <div class="novamira-rm-compare">
        <div class="novamira-rm-card">
            <h2><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e(
                'The agent can use',
                domain: 'novamira',
            ); ?></h2>
            <ul class="novamira-rm-list">
                <li><?php esc_html_e('Files and folders of the WordPress installation', domain: 'novamira'); ?></li>
                <li><?php esc_html_e('Basic site information', domain: 'novamira'); ?></li>
                <li><?php esc_html_e(
                    'Posts, terms, users, comments, options and the database (with Novamira Pro)',
                    domain: 'novamira',
                ); ?></li>
                <li><?php esc_html_e(
                    'Read abilities of the Novamira Pro specializations, and its memories (with Novamira Pro)',
                    domain: 'novamira',
                ); ?></li>
            </ul>
        </div>
        <div class="novamira-rm-card">
            <h2><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php esc_html_e(
                'Turned off',
                domain: 'novamira',
            ); ?></h2>
            <ul class="novamira-rm-list">
                <li><?php esc_html_e('PHP execution and WP-CLI', domain: 'novamira'); ?></li>
                <li><?php esc_html_e('Writing files, uploads and admin access links', domain: 'novamira'); ?></li>
                <li><?php esc_html_e('Skills and Novamira Design', domain: 'novamira'); ?></li>
                <li><?php esc_html_e(
                    'Abilities added by other plugins, unless you allow them',
                    domain: 'novamira',
                ); ?></li>
            </ul>
        </div>
    </div>
    <?php }

/** Read Mode is part of Novamira Pro: where to get it, or to update it. */
function render_pro_card(): void
{
    if (\novamira_pro_is_active()) {
        ?>
        <div class="novamira-rm-card novamira-rm-pro">
            <p><?php esc_html_e(
                'Read Mode needs a newer version of Novamira Pro. Update Novamira Pro to turn it on.',
                domain: 'novamira',
            ); ?></p>
            <a href="<?php echo
                esc_url(admin_url('update-core.php'))
            ; ?>" class="button button-primary"><?php esc_html_e('Go to Updates', domain: 'novamira'); ?></a>
        </div>
        <?php

        return;
    }
    ?>
    <div class="novamira-rm-card novamira-rm-pro">
        <p><?php esc_html_e(
            'Read Mode is part of Novamira Pro. Turning it on needs Novamira Pro with an active license.',
            domain: 'novamira',
        ); ?></p>
        <a href="<?php echo
            esc_url(NOVAMIRA_PRO_URL . '?utm_source=plugin&utm_medium=read_mode')
        ; ?>" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e(
            'Get Novamira Pro',
            domain: 'novamira',
        ); ?></a>
    </div>
    <?php
}

/**
 * The card that turns read mode off, while it is on. Turning it off needs no license.
 *
 * @param array{state: 'off'|'on'|'unreadable', active: bool, updated_at: int, updated_by: int, source: string, pro_version: string, schema_version: int} $status
 */
function render_turn_off(array $status): void
{
    if (!$status['active']) {
        return;
    }
    ?>
    <div class="novamira-rm-card novamira-rm-off">
        <h2><?php esc_html_e('Turn off Read Mode', domain: 'novamira'); ?></h2>
        <?php render_disable_form(); ?>
    </div>
    <?php
}

function render_disable_form(): void
{ ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(DISABLE_ACTION); ?>">
        <?php wp_nonce_field(DISABLE_ACTION); ?>
        <p>
            <label>
                <input type="checkbox" name="confirm" value="1" required>
                <?php esc_html_e(
                    'I understand that Novamira will offer AI agents its write abilities again.',
                    domain: 'novamira',
                ); ?>
            </label>
        </p>
        <?php submit_button(
            __('Turn off Read Mode', domain: 'novamira'),
            type: 'secondary',
            name: 'submit',
            wrap: false,
        ); ?>
    </form>
    <?php }

/** Turn read mode off from the Read Mode page. Needs the capability, the nonce, and the confirmation, not a license. */
function handle_disable(): void
{
    if (!admin_ui_visible()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', domain: 'default'), args: [
            'response' => 403,
        ]);
    }
    check_admin_referer(DISABLE_ACTION);
    if (wp_unslash($_POST['confirm'] ?? '') !== '1') {
        wp_die(esc_html__('Confirm that AI agents will be able to change this site again.', domain: 'novamira'), args: [
            'response' => 400,
        ]);
    }

    $error = disable(get_current_user_id(), source: 'free-ui');
    if ($error instanceof WP_Error) {
        wp_die(esc_html($error->get_error_message()), args: ['response' => 500]);
    }

    wp_safe_redirect(add_query_arg(['novamira_read_mode' => 'disabled'], page_url()));
    exit();
}

/**
 * Novamira's admin-bar node in read mode: only its label and link change, to "Novamira · Read Mode" and
 * the Read Mode page. Its classes, colors, and narrow-screen behavior stay as they are outside read mode,
 * where the node is returned unchanged.
 *
 * @param array{id: string, title: string, href: string, meta: array{class: string}} $node
 * @return array{id: string, title: string, href: string, meta: array{class: string}}
 */
function admin_bar_node(array $node): array
{
    if (!\novamira_is_read_mode()) {
        return $node;
    }

    $node['title'] = esc_html__('Novamira · Read Mode', domain: 'novamira');
    $node['href'] = page_url();

    return $node;
}

function describe_user(int $user_id): string
{
    if ($user_id === 0) {
        return __('WP-CLI', domain: 'novamira');
    }

    $user = get_userdata($user_id);
    if ($user === false) {
        /* translators: %d: user ID. */
        return sprintf(__('User #%d', domain: 'novamira'), $user_id);
    }

    return $user->user_login;
}
