<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Doors that do not go through Ability registration refuse to act in read mode, and every door that
 * resolves a removed Ability explains why with the shared read-mode wording.
 */
final class ReadModeRoutesTest extends TestCase
{
    public function testUploadAndAdminAccessLinksAreRefusedBeforeTheirTokensAreRead(): void
    {
        $result = self::runScenario('links-on');

        foreach (['upload', 'exchange', 'login'] as $route) {
            self::assertSame('novamira_read_mode', $result[$route]['error'], $route);
            self::assertSame(['status' => 403], $result[$route]['data'], $route);
            self::assertStringContainsString('Do NOT try to make the change by other means', $result[$route]['message']);
        }
        self::assertStringContainsString('upload link', $result['upload']['message']);
        self::assertStringContainsString('admin access link', $result['exchange']['message']);

        // A token issued before read mode was turned on is neither read nor consumed.
        self::assertSame([], $result['calls']);
    }

    public function testLinksUseTheReadModeWordingEvenWhileAiAbilitiesAreOff(): void
    {
        $result = self::runScenario('links-disabled-on');

        foreach (['upload', 'exchange', 'login'] as $route) {
            self::assertSame('novamira_read_mode', $result[$route]['error'], $route);
        }
        self::assertSame([], $result['calls']);

        $off = self::runScenario('links-disabled-off');
        foreach (['upload', 'exchange', 'login'] as $route) {
            self::assertSame('novamira_disabled', $off[$route]['error'], $route);
        }
    }

    public function testLinksBehaveAsBeforeOutsideReadMode(): void
    {
        $result = self::runScenario('links-off');

        self::assertSame('invalid_upload_token', $result['upload']['error']);
        self::assertSame('invalid_admin_access_token', $result['exchange']['error']);
        self::assertSame('invalid_admin_access_nonce', $result['login']['error']);
        self::assertContains(['get_header', 'x-novamira-upload-token'], $result['calls']);
        self::assertSame('get_transient', $result['calls'][3][0]);
    }

    public function testRestShimExplainsReadMode(): void
    {
        $on = self::runScenario('shim-on');
        self::assertSame('novamira_read_mode', $on['blocked']['error']);
        self::assertSame(['status' => 403], $on['blocked']['data']);
        // An allowed Ability that is simply not registered keeps the ordinary not-found error.
        self::assertSame('novamira_ability_not_found', $on['allowed_but_missing']['error']);

        $off = self::runScenario('shim-off');
        self::assertSame('novamira_ability_not_found', $off['blocked']['error']);
        self::assertSame(['status' => 404], $off['blocked']['data']);
    }

    public function testCoreAbilitiesRunRouteExplainsReadModeToManagersOnly(): void
    {
        $on = self::runScenario('core-run-on');
        self::assertSame('novamira_read_mode', $on['blocked']['error']);
        self::assertNull($on['allowed']);
        self::assertSame('rest_ability_not_found', $on['non_manager']['error']);

        $off = self::runScenario('core-run-off');
        self::assertNull($off['blocked']);
        self::assertNull($off['allowed']);
        self::assertSame('rest_ability_not_found', $off['non_manager']['error']);
    }

    public function testGetAbilityInfoExplainsReadMode(): void
    {
        $on = self::runScenario('ability-info-on');
        self::assertSame('novamira_read_mode', $on['blocked']['error']);
        self::assertNull($on['allowed']);

        self::assertSame(['blocked' => null, 'allowed' => null], self::runScenario('ability-info-off'));
    }

    public function testVisualBackendToolsExplainReadMode(): void
    {
        self::assertSame('novamira_read_mode', self::runScenario('visual-on')['blocked']['error']);
        self::assertSame(
            'novamira_visual_backend_tool_unavailable',
            self::runScenario('visual-off')['blocked']['error'],
        );
    }

    /** Novamira Visual does not work in read mode: its backend tools refuse every request, an allowed Ability included. */
    public function testVisualBackendToolsRefuseEverythingInReadMode(): void
    {
        $result = self::runScenario('visual-backend-on');

        foreach (['discover', 'call'] as $request) {
            self::assertSame('novamira_read_mode', $result[$request]['error'], $request);
            self::assertSame(['status' => 403], $result[$request]['data'], $request);
            self::assertStringContainsString('so Novamira Visual is unavailable', $result[$request]['message'], $request);
            self::assertStringContainsString('Do NOT try to make the change by other means', $result[$request]['message'], $request);
        }
    }

    /** The workspace page says why it does not open, and the session check tells an open workspace to stop. */
    public function testVisualWorkspaceShowsTheLimitationInReadMode(): void
    {
        $html = self::runScenario('visual-page-on')['html'];
        self::assertStringContainsString('Novamira Visual is unavailable', $html);
        self::assertStringContainsString('Read Mode is on, so Novamira Visual does not work. Turn off Read Mode to use it again.', $html);
        self::assertStringNotContainsString('novamira-visual-workspace-root', $html);

        $on = self::runScenario('visual-session-on')['session'];
        self::assertTrue($on['readMode']);
        self::assertStringContainsString('so Novamira Visual is unavailable', $on['readModeMessage']);

        $off = self::runScenario('visual-session-off')['session'];
        self::assertFalse($off['readMode']);
        self::assertSame('', $off['readModeMessage']);
    }

    /**
     * The editor bundle and the workspace page learn the read-mode state from their localized data, so
     * they can withhold the in-editor tools that write and refuse the page actions that can change the site.
     */
    public function testVisualScriptsReceiveTheReadModeState(): void
    {
        $on = self::runScenario('visual-localize-on');
        self::assertTrue($on['editor_read_mode']);
        self::assertTrue($on['workspace_read_mode']);
        self::assertStringContainsString('so {subject} is unavailable', $on['workspace_message']);
        self::assertStringContainsString('Do NOT try to make the change by other means', $on['workspace_message']);

        $off = self::runScenario('visual-localize-off');
        self::assertSame(
            ['editor_read_mode' => false, 'workspace_read_mode' => false, 'workspace_message' => ''],
            $off,
        );
    }

    /**
     * Novamira's executors check the read-mode allowlist at execution time, so an Ability that is still
     * registered (for example by a provider that ran after the sweep) is refused and never executed.
     */
    public function testExecutorsRefuseARegisteredAbilityOutsideTheAllowlist(): void
    {
        $result = self::runScenario('late-ability-on');

        foreach (['shim', 'chat'] as $executor) {
            self::assertSame('novamira_read_mode', $result[$executor]['error'], $executor);
            self::assertSame(['status' => 403], $result[$executor]['data'], $executor);
            self::assertStringContainsString("'acme/late-write'", $result[$executor]['message'], $executor);
        }
        // Novamira Visual refuses everything in read mode, whatever the Ability.
        self::assertSame('novamira_read_mode', $result['visual']['error']);
        self::assertSame(['status' => 403], $result['visual']['data']);
        self::assertStringContainsString('so Novamira Visual is unavailable', $result['visual']['message']);
        foreach (['tool', 'prompt', 'resource', 'claims_allowed_name', 'not_ability_backed'] as $component) {
            self::assertSame('novamira_read_mode', $result['mcp'][$component]['error'], $component);
        }
        self::assertFalse($result['discover_lists_late']);
        self::assertSame(0, $result['executions']);
        // Every surface sweeps the registry on entry, before listing, checking, or executing, except Novamira
        // Visual, which refuses everything in read mode before it gets that far.
        self::assertSame(
            ['rest_shim' => true, 'chat_discover' => true, 'chat_prepare' => true, 'visual_discover' => false, 'visual_call' => false],
            $result['swept_on_entry'],
        );
    }

    public function testExecutorsAreUnchangedOutsideReadMode(): void
    {
        $result = self::runScenario('late-ability-off');

        self::assertSame('written', $result['shim']);
        self::assertSame(['ability_name' => 'acme/late-write'], $result['chat']);
        self::assertSame(['name' => 'acme/late-write', 'result' => 'written'], $result['visual']);
        self::assertSame(
            [
                'tool' => ['a' => 1],
                'prompt' => ['a' => 1],
                'resource' => ['a' => 1],
                'claims_allowed_name' => ['a' => 1],
                'not_ability_backed' => ['a' => 1],
            ],
            $result['mcp'],
        );
        self::assertTrue($result['discover_lists_late']);
        self::assertSame(2, $result['executions']);
        self::assertSame(
            ['rest_shim' => false, 'chat_discover' => false, 'chat_prepare' => false, 'visual_discover' => false, 'visual_call' => false],
            $result['swept_on_entry'],
        );
    }

    /** @return array<array-key, mixed> */
    private static function runScenario(string $scenario): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, $root . '/tests/fixtures/read-mode-routes-runner.php', $root, $scenario];
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
