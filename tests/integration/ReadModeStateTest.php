<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The read-mode state and the surfaces Novamira owns around it: the authoritative read, the API Novamira
 * Pro turns read mode on with, the Read Mode page, WP-CLI, the admin bar, the admin notice, and the
 * notices channel of the agent instructions.
 */
final class ReadModeStateTest extends TestCase
{
    public function testStoredValuesAndDamagedRecordsKeepReadModeOn(): void
    {
        self::assertSame(
            [
                'absent' => ['read_mode' => false, 'state' => 'off'],
                'on' => ['read_mode' => true, 'state' => 'on'],
                'off' => ['read_mode' => false, 'state' => 'off'],
                'legacy on' => ['read_mode' => true, 'state' => 'on'],
                'legacy off' => ['read_mode' => false, 'state' => 'off'],
                'legacy other string' => ['read_mode' => true, 'state' => 'unreadable'],
                'malformed truncated' => ['read_mode' => true, 'state' => 'unreadable'],
                'malformed length' => ['read_mode' => true, 'state' => 'unreadable'],
                'serialized false' => ['read_mode' => true, 'state' => 'unreadable'],
                'trailing data' => ['read_mode' => true, 'state' => 'unreadable'],
                'not canonical' => ['read_mode' => true, 'state' => 'unreadable'],
                'no enabled flag' => ['read_mode' => true, 'state' => 'unreadable'],
                'enabled not boolean' => ['read_mode' => true, 'state' => 'unreadable'],
                'object' => ['read_mode' => true, 'state' => 'unreadable'],
            ],
            self::runScenario('state-values'),
        );
    }

    /**
     * Option filters and the object cache cannot make read mode read as off: the state comes straight
     * from the options table, once per request.
     */
    public function testStateIsReadFromTheOptionsTableAndIgnoresOptionFiltersAndCaches(): void
    {
        $result = self::runScenario('state-filters');

        self::assertTrue($result['read_mode']);
        self::assertSame(['enabled' => false], $result['filtered_option']);
        self::assertSame([], $result['consulted']);
        self::assertSame(1, $result['state_queries']);
        self::assertSame(1, $result['state_queries_after_more_checks']);
        self::assertSame(
            "SELECT option_value FROM wp_options WHERE option_name = 'novamira_read_mode' LIMIT 1",
            $result['query'],
        );
    }

    public function testADatabaseThatDoesNotAnswerCountsAsOnAndIsReadAgain(): void
    {
        self::assertSame(['while_failing' => true, 'after_recovery' => false], self::runScenario('state-database-error'));
    }

    public function testEachSiteOfANetworkHasItsOwnState(): void
    {
        self::assertSame(
            ['site_1' => true, 'site_2' => false, 'site_1_again' => true],
            self::runScenario('state-sites'),
        );
    }

    public function testApiEnablesOnlyFromProOrCliAndRecordsWhoChangedIt(): void
    {
        $result = self::runScenario('api');

        self::assertSame('off', $result['absent']['state']);
        self::assertNull($result['disable_when_absent']);
        self::assertSame(0, $result['writes_when_absent']);
        self::assertSame('novamira_read_mode_invalid_source', $result['enable_from_free_ui']['error']);
        self::assertSame('novamira_read_mode_invalid_source', $result['enable_unknown_source']['error']);
        self::assertFalse($result['still_off']);

        self::assertNull($result['enable_from_pro']);
        self::assertTrue($result['on']);
        self::assertSame(
            ['enabled' => true, 'schema_version' => 1, 'updated_by' => 7, 'source' => 'pro', 'pro_version' => '2.4.0', 'recent' => true],
            $result['stored'],
        );
        self::assertSame(
            ['state' => 'on', 'active' => true, 'updated_by' => 7, 'source' => 'pro', 'pro_version' => '2.4.0', 'schema_version' => 1],
            $result['status_on'],
        );
        // WordPress' own option caches are cleared, so get_option() sees the change too.
        self::assertSame(
            [['novamira_read_mode', 'options'], ['notoptions', 'options'], ['alloptions', 'options']],
            $result['cache_deletes'],
        );

        self::assertSame('novamira_read_mode_invalid_source', $result['disable_unknown_source']['error']);
        self::assertNull($result['disable_from_cli']);
        self::assertFalse($result['off']);
        self::assertSame('cli', $result['status_off']['source']);
        self::assertSame('2.4.0', $result['status_off']['pro_version']);

        self::assertSame('unreadable', $result['damaged']);
        self::assertNull($result['disable_damaged']);
        self::assertSame('off', $result['repaired']);

        self::assertSame('novamira_read_mode_write_failed', $result['failed_write']['error']);
        self::assertStringContainsString('INSERT command denied', $result['failed_write']['message']);
        self::assertFalse($result['after_failed_write']);

        self::assertSame(
            ['schema' => 1, 'runtime' => 1, 'option' => 'novamira_read_mode', 'panel_hook' => 'novamira_read_mode_panel'],
            $result['constants'],
        );
    }

    public function testChangesThroughWordPressOptionFunctionsAreSeen(): void
    {
        self::assertSame(
            ['before' => false, 'after_unrelated_option' => false, 'after_own_option' => true],
            self::runScenario('option-change-hooks'),
        );
    }

    public function testStateQueryIsKeptOutOfW3TotalCachesQueryCache(): void
    {
        self::assertSame(
            ['state_query' => 'novamira read mode state', 'other_query' => '', 'other_reason_kept' => 'modification query'],
            self::runScenario('query-cache'),
        );
    }

    public function testPageWithoutProExplainsReadModeAndThatItNeedsNovamiraPro(): void
    {
        $html = self::runScenario('page-off')['html'];

        // What the agent can use and what is turned off, in plain words.
        self::assertStringContainsString('The agent can use', $html);
        self::assertStringContainsString('Files and folders of the WordPress installation', $html);
        self::assertStringContainsString('(with Novamira Pro)', $html);
        self::assertStringContainsString('Turned off', $html);
        self::assertStringContainsString('PHP execution and WP-CLI', $html);

        // It is part of Novamira Pro and needs a license.
        self::assertStringContainsString(
            'Read Mode is part of Novamira Pro. Turning it on needs Novamira Pro with an active license.',
            $html,
        );
        self::assertStringContainsString('Get Novamira Pro', $html);
        self::assertStringNotContainsString('novamira_read_mode_disable', $html);
        self::assertStringNotContainsString('>Status<', $html);
        // Novamira offers no way to turn read mode on: no form at all while it is off.
        self::assertStringNotContainsString('<form', $html);
    }

    public function testPageInReadModeOffersTheDisableFormWithoutPro(): void
    {
        $html = self::runScenario('page-on')['html'];

        self::assertStringContainsString('novamira-rm-pill is-alert', $html);
        self::assertStringContainsString('Needs attention', $html);
        self::assertStringContainsString('Last changed 2023-11-14 22:13 by agency-admin.', $html);
        self::assertStringContainsString('name="action" value="novamira_read_mode_disable"', $html);
        self::assertStringContainsString('value="nonce-novamira_read_mode_disable"', $html);
        self::assertStringContainsString('name="confirm" value="1" required', $html);
        // On without Pro: the page says what the agent can still read, not how to turn read mode on.
        self::assertStringContainsString('Read Mode is on but not working as intended: it needs Novamira Pro to be active', $html);
        self::assertStringContainsString('Novamira keeps its write abilities turned off.', $html);
        self::assertStringNotContainsString('can only read', $html);
        self::assertStringNotContainsString('Read Mode is part of Novamira Pro', $html);
    }

    /** With Novamira Pro's abilities the status is plainly On; with a setting that is not valid it needs attention. */
    public function testStatusPillFollowsWhatIsActuallyAvailable(): void
    {
        $with_pro = self::runScenario('page-on-with-pro-abilities')['html'];
        self::assertStringContainsString('novamira-rm-pill is-on', $with_pro);
        self::assertStringNotContainsString('Needs attention', $with_pro);
        self::assertStringNotContainsString('not working as intended', $with_pro);

        $invalid = self::runScenario('page-unreadable')['html'];
        self::assertStringContainsString('novamira-rm-pill is-alert', $invalid);
        self::assertStringContainsString(
            'The saved Read Mode setting is not valid, so Read Mode stays on. Turn it off to reset it.',
            $invalid,
        );
        self::assertStringContainsString('name="action" value="novamira_read_mode_disable"', $invalid);
    }

    public function testPageInReadModeWithAnOlderProSaysToUpdateIt(): void
    {
        $html = self::runScenario('page-on-pro-outdated')['html'];

        self::assertStringContainsString(
            'Read Mode is on but not working as intended: this version of Novamira Pro does not provide its read abilities.',
            $html,
        );
        self::assertStringContainsString('name="action" value="novamira_read_mode_disable"', $html);
    }

    /** The limits come first, in a visible note styled like the Ghost Mode one, not in small print. */
    public function testPageStatesTheLimitsAtTheTop(): void
    {
        foreach (['page-off' => 'The agent can use', 'page-on' => '>Status<'] as $scenario => $next) {
            $html = self::runScenario($scenario)['html'];

            self::assertStringContainsString('class="novamira-rm-security" role="note"', $html, $scenario);
            self::assertStringContainsString('Read Mode applies to Novamira.', $html, $scenario);
            self::assertStringContainsString(
                'It does not turn off the other tools of your AI client: a browser or another MCP server can still make changes if you leave them enabled.',
                $html,
                $scenario,
            );
            self::assertStringContainsString(
                'Read Mode is not a security feature: the agent connects with an administrator&#039;s credential and can read everything an administrator can read.',
                $html,
                $scenario,
            );
            self::assertStringNotContainsString('may propose', $html, $scenario);
            self::assertStringContainsString('Some things can still change.', $html, $scenario);
            self::assertStringNotContainsString('Sandbox plugins keep running', $html, $scenario);
            self::assertStringContainsString(
                'A read ability may change some of the site&#039;s temporary data as part of reading, for example by clearing an expired transient, rebuilding a cache or a generated file, or updating a counter. Other plugins can also react to a read through their own hooks, for example by emptying a page cache.',
                $html,
                $scenario,
            );
            self::assertLessThan(strpos($html, $next), strpos($html, 'Read Mode applies to Novamira.'), $scenario);
            self::assertStringContainsString('class="novamira-rm-lead"', $html, $scenario);
            self::assertStringContainsString('Let AI agents look at this site without Novamira&#039;s write abilities.', $html, $scenario);
            self::assertStringNotContainsString('class="description"', $html, $scenario);
            self::assertStringNotContainsString('guards against', $html, $scenario);
            self::assertStringNotContainsString('security boundary', $html, $scenario);
        }
    }

    public function testOnlyTheFeaturesScreenSavesNothingWhileReadModeIsOn(): void
    {
        // The Features screen cannot be changed.
        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=novamira-features&novamira_result=read_mode',
            self::runScenario('features-post-on')['redirect'],
        );
        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=novamira-features&novamira_result=invalid',
            self::runScenario('features-post-off')['redirect'],
        );

        // The Abilities Hub can: a request reaches the checks that follow, as outside read mode.
        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=novamira-abilities&novamira_result=invalid',
            self::runScenario('hub-post-on')['redirect'],
        );
        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=novamira-abilities&novamira_result=invalid',
            self::runScenario('hub-post-off')['redirect'],
        );

        // But only for the read abilities read mode does not require: it keeps its own on.
        self::assertSame(
            'https://example.test/wp-admin/admin.php?page=novamira-abilities&novamira_result=managed',
            self::runScenario('hub-group-on')['redirect'],
        );
        $ajax = self::runScenario('hub-ajax-on');
        self::assertSame(403, $ajax['status']);
        self::assertSame('This ability cannot be changed.', $ajax['json_error']['message']);
    }

    /** While read mode is on, each read ability it does not require is turned off and on by itself. */
    public function testAbilitiesHubTurnsReadAbilitiesOffAndOnExceptTheOnesReadModeRequires(): void
    {
        $result = self::runScenario('hub-manageable-on');

        self::assertSame(['required' => false, 'chosen' => true, 'not_allowed' => false], $result['can_manage']);
        self::assertSame(
            [
                'novamira/read-file' => [
                    'manageable' => false,
                    'infrastructure' => true,
                    'disabled' => false,
                    'label' => 'Required by Read Mode',
                ],
                'novamira/elementor-get-page' => [
                    'manageable' => true,
                    'infrastructure' => false,
                    'disabled' => true,
                    'label' => null,
                ],
            ],
            $result['rows'],
        );
        self::assertSame(['required' => false, 'chosen' => true], $result['toggled']);
    }

    /** The Abilities Hub says that only read abilities are listed and where the groups are chosen. */
    public function testAbilitiesHubNoticeWhileReadModeIsOn(): void
    {
        $html = self::runScenario('hub-notice-on')['html'];

        self::assertStringContainsString('Read Mode is on, so only read abilities are listed.', $html);
        self::assertStringContainsString('you choose the groups of read abilities on the Read Mode page', $html);
        // A session already open over STDIO reads the Hub's choices once, so it needs a restart to see a change.
        self::assertStringContainsString('An MCP session that is already open over STDIO needs a restart to see a change.', $html);
        self::assertStringContainsString('Go to Read Mode', $html);
        self::assertStringContainsString('<div id="pro-notice">on</div>', $html);
    }
    public function testFeaturesScreenExplainsAndLocksItsControlsWhileReadModeIsOn(): void
    {
        $on = self::runScenario('features-notice-on');
        self::assertStringContainsString('Features cannot be changed until Read Mode is off.', $on['notice']);
        self::assertStringContainsString('Read Mode settings', $on['notice']);
        self::assertSame('', self::runScenario('features-notice-off')['notice']);
    }

    /** Read Mode sits next to the Abilities Hub, since both decide what agents can use. */
    public function testMenuEntryMovesNextToTheAbilitiesHub(): void
    {
        $moved = self::runScenario('menu-order');
        self::assertSame(
            [
                'novamira-connect',
                'novamira-abilities',
                'novamira-read-mode',
                'novamira-sandbox',
                'novamira-troubleshoot',
                'novamira-features',
                'novamira-ghost-mode',
            ],
            $moved['order'],
        );
        // Other menus are not touched.
        self::assertSame([['Other', 'manage_options', 'other', 'Other']], $moved['other']);

        // Without the Abilities Hub the entry goes last; when Ghost Mode hid it, nothing changes.
        self::assertSame(
            ['novamira-connect', 'novamira-sandbox', 'novamira-troubleshoot', 'novamira-features', 'novamira-ghost-mode', 'novamira-read-mode'],
            self::runScenario('menu-order-without-hub')['order'],
        );
        self::assertSame(
            ['novamira-connect', 'novamira-abilities', 'novamira-sandbox', 'novamira-troubleshoot', 'novamira-features', 'novamira-ghost-mode'],
            self::runScenario('menu-order-without-read-mode')['order'],
        );
    }

    public function testPageDelegatesTheEnablePanelToPro(): void
    {
        $with_panel = self::runScenario('page-on-pro-panel');
        self::assertSame(['novamira_read_mode_panel'], $with_panel['actions']);
        self::assertStringContainsString('<div id="pro-panel">Pro panel, state on</div>', $with_panel['html']);
        self::assertStringContainsString('novamira_read_mode_disable', $with_panel['html']);
        self::assertStringNotContainsString('Activate Novamira Pro', $with_panel['html']);

        $outdated = self::runScenario('page-off-pro-outdated')['html'];
        self::assertStringContainsString('needs a newer version of Novamira Pro', $outdated);
    }

    public function testPageRespectsGhostMode(): void
    {
        self::assertSame(
            ['died' => 'Sorry, you are not allowed to access this page.', 'status' => 403],
            self::runScenario('page-hidden'),
        );
    }

    public function testDisableNeedsCapabilityNonceAndConfirmationButNoLicense(): void
    {
        $ok = self::runScenario('disable-ok');
        self::assertSame('https://example.test/wp-admin/admin.php?page=novamira-read-mode&novamira_read_mode=disabled', $ok['redirect']);
        self::assertSame('off', $ok['state']['state']);
        self::assertSame('free-ui', $ok['state']['source']);
        self::assertSame(7, $ok['state']['updated_by']);

        foreach (['disable-unconfirmed', 'disable-confirm-empty', 'disable-confirm-zero'] as $unconfirmed) {
            self::assertSame(400, self::runScenario($unconfirmed)['status'], $unconfirmed);
        }
        self::assertSame(403, self::runScenario('disable-forbidden')['status']);
        self::assertSame(403, self::runScenario('disable-hidden')['status']);
    }

    public function testCliShowsStatusAndDisables(): void
    {
        $on = self::runScenario('cli-on');
        self::assertSame(
            [
                ['command', 'novamira read-mode status'],
                ['command', 'novamira read-mode disable'],
                ['line', 'Read Mode: on'],
                ['line', 'Last change: 2023-11-14 22:13 by User #1'],
                ['confirm', 'Turn off Read Mode? Novamira will offer AI agents its write abilities again.'],
                ['success', 'Read Mode is off.'],
                ['line', 'Read Mode: off'],
            ],
            array_slice($on['out'], 0, 7),
        );
        self::assertSame('cli', $on['stored_source']);

        // There is no enable command in Novamira.
        $off = self::runScenario('cli-off');
        self::assertSame(['novamira read-mode status', 'novamira read-mode disable'], array_column(
            array_values(array_filter($off['out'], static fn(array $entry): bool => $entry[0] === 'command')),
            1,
        ));
        self::assertNull($off['stored_source']);
    }

    public function testAdminBarNodeShowsReadModeAndIsUnchangedOutsideIt(): void
    {
        self::assertSame(
            [
                'id' => 'novamira-mcp-status',
                'title' => 'Novamira · Read Mode',
                'href' => 'https://example.test/wp-admin/admin.php?page=novamira-read-mode',
                // The class, and so the colors, are those the node has outside read mode.
                'meta' => ['class' => 'novamira-mcp-on'],
            ],
            self::runScenario('admin-bar-on')['node'],
        );
        self::assertSame(
            [
                'id' => 'novamira-mcp-status',
                'title' => 'Novamira ON',
                'href' => 'https://example.test/wp-admin/admin.php?page=novamira-connect',
                'meta' => ['class' => 'novamira-mcp-on'],
            ],
            self::runScenario('admin-bar-off')['node'],
        );
    }

    /** Read mode changes only the node's label and link: no admin-bar style of its own, on any screen width. */
    public function testAdminBarHasNoReadModeStyles(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, levels: 2) . '/novamira.php');

        self::assertStringNotContainsString('.novamira-read-mode', $plugin);
        self::assertStringNotContainsString('ab-icon', $plugin);
    }

    /** The Read Mode page, the Abilities Hub and the Features page have their own notice, so this one is not repeated there. */
    public function testNoticeShowsOnNovamiraScreensIncludingChat(): void
    {
        $chat = self::runScenario('notice-chat')['html'];
        self::assertStringContainsString('Novamira Read Mode is on', $chat);
        self::assertStringContainsString('href="https://example.test/wp-admin/admin.php?page=novamira-read-mode"', $chat);

        foreach (['notice-read-mode-page', 'notice-hub', 'notice-features', 'notice-other-screen', 'notice-hidden', 'notice-off'] as $scenario) {
            self::assertSame('', self::runScenario($scenario)['html'], $scenario);
        }
    }

    /** @return array<array-key, mixed> */
    private static function runScenario(string $scenario): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, $root . '/tests/fixtures/read-mode-state-runner.php', $root, $scenario];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);

        self::assertSame(0, $exit_code, "stdout:\n{$stdout}\nstderr:\n{$stderr}");
        self::assertSame('', $stderr, $stderr);

        $decoded = json_decode($stdout, associative: true);
        self::assertIsArray($decoded, $stdout);
        self::assertArrayNotHasKey('unknown_scenario', $decoded);

        return $decoded;
    }
}
