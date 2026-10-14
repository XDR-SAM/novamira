<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * While read mode is on, the Abilities Hub lets the administrator turn single read abilities off and on.
 * An ability turned off cannot be turned on again once its rule has taken it out of the registry, unless
 * the switch accepts a rule it can remove, and an ability that is off must be refused wherever it is
 * reached from, not only left out of the lists. Outside read mode none of this changes.
 */
final class ReadModeHubRulesTest extends TestCase
{
    public function testAnAbilityOfAnotherPluginTurnedOffInTheHubTurnsOnAgain(): void
    {
        $result = self::runScenario('toggle-on-again');

        self::assertTrue($result['registered_first']);
        self::assertTrue($result['turn_off']['success']);
        self::assertTrue($result['turn_off']['payload']['disabled']);

        // The rule has taken it out of the registry, so the next request cannot find it.
        self::assertFalse($result['registered_when_off']);
        self::assertFalse($result['usable_when_off']);

        self::assertTrue($result['turn_on']['success'], 'The switch refused to turn the ability on again.');
        self::assertFalse($result['turn_on']['payload']['disabled']);
        self::assertSame([], $result['rules_after_on']);
        self::assertTrue($result['registered_after_on']);
        self::assertTrue($result['usable_after_on']);
    }

    public function testAnAbilityOfNovamiraTheAdministratorChoseTurnsOnAgain(): void
    {
        $result = self::runScenario('toggle-on-again-chosen');

        self::assertTrue($result['turn_off']['success']);
        self::assertFalse($result['registered_when_off']);
        self::assertTrue($result['turn_on']['success']);
        self::assertSame([], $result['rules_after_on']);
        self::assertTrue($result['usable_after_on']);
    }

    /**
     * Removing a rule allows nothing by itself: a name without a rule cannot be turned on, a required
     * ability cannot be turned off, and everything outside read mode's list stays refused.
     */
    public function testTurningOnAgainGrantsNothing(): void
    {
        $result = self::runScenario('toggle-grants-nothing');

        self::assertFalse($result['answers']['acme/never-ruled']['success']);
        self::assertSame(403, $result['answers']['acme/never-ruled']['status']);
        self::assertFalse($result['answers']['novamira/read-file']['success']);
        self::assertSame(403, $result['answers']['novamira/read-file']['status']);
        self::assertTrue($result['answers']['acme/gone']['success']);
        self::assertTrue($result['answers']['novamira/execute-php']['success']);
        self::assertSame([], $result['rules']);

        self::assertFalse($result['execute_php_registered']);
        foreach (['execute_php_error', 'never_ruled_error', 'gone_error'] as $error) {
            self::assertSame('novamira_read_mode', $result[$error]['error'], $error);
            self::assertSame(['status' => 403], $result[$error]['data'], $error);
        }
        self::assertTrue($result['read_file_usable']);
    }

    public function testTheSwitchOutsideReadModeIsUnchanged(): void
    {
        $result = self::runScenario('toggle-read-mode-off');

        self::assertTrue($result['off']['success']);
        self::assertTrue($result['off']['payload']['disabled']);
        self::assertSame(['acme/unregistered' => ['disabled' => true]], $result['rules_after_off']);
        self::assertTrue($result['on']['success']);
        self::assertFalse($result['on']['payload']['disabled']);
        self::assertSame([], $result['rules_after_on']);
    }

    /**
     * An ability object another plugin holds from before the Hub turned the ability off is refused too,
     * whichever of read mode's checks it reaches, and the required ability ignores the rule.
     */
    public function testAnObjectHeldBeforeTheHubTurnedTheAbilityOffIsRefused(): void
    {
        $result = self::runScenario('kept-objects');

        foreach (['novamira/read-file', 'novamira/get-object', 'acme/report'] as $name) {
            self::assertArrayHasKey('ran', $result['before'][$name], $name);
        }

        self::assertArrayHasKey('ran', $result['after']['novamira/read-file']);
        self::assertNull($result['refusals']['novamira/read-file']);
        self::assertNull($result['pre_execute']['novamira/read-file']);
        foreach (['novamira/get-object', 'acme/report'] as $name) {
            self::assertSame(['refused' => true], $result['after'][$name], $name . ' still ran.');
            self::assertSame('novamira_read_mode', $result['refusals'][$name]['error'] ?? null, $name);
            self::assertSame('novamira_read_mode', $result['pre_execute'][$name]['error'] ?? null, $name);
        }
    }

    /** An ability registered again after the policy ran is refused wherever it is reached from. */
    public function testAnAbilityRegisteredAgainAfterThePolicyIsRefused(): void
    {
        $result = self::runScenario('late-registration');

        self::assertSame(['novamira/read-file'], $result['after_policy']);
        self::assertArrayHasKey('ran', $result['run']['novamira/read-file']);
        self::assertNull($result['refusals']['novamira/read-file']);
        self::assertNull($result['by_name']['novamira/read-file']);
        self::assertNull($result['execute_ability']['novamira/read-file']);

        foreach (['novamira/get-object', 'acme/report'] as $name) {
            self::assertSame(['refused' => true], $result['run'][$name], $name . ' still ran.');
            self::assertSame('novamira_read_mode', $result['refusals'][$name]['error'] ?? null, $name);
            self::assertSame('novamira_read_mode', $result['by_name'][$name]['error'] ?? null, $name);
            self::assertSame('novamira_read_mode', $result['execute_ability'][$name]['error'] ?? null, $name);
            self::assertStringContainsString('Read mode is active', $result['execute_ability'][$name]['message'] ?? '');
        }

        // The next entry to the registry removes what the Hub turned off.
        self::assertSame(['novamira/read-file'], $result['after_sweep']);
    }

    /** The Hub lists what it turned off, so its screen keeps those abilities registered. */
    public function testTheHubScreenKeepsTheAbilitiesItTurnedOff(): void
    {
        $result = self::runScenario('management-screen');

        self::assertEqualsCanonicalizing(
            ['novamira/read-file', 'novamira/get-object', 'acme/report'],
            $result['registered'],
        );
    }

    /** With read mode off the Hub rules are handled as before: read mode's checks do not look at them. */
    public function testReadModeChecksIgnoreTheHubRulesWhenReadModeIsOff(): void
    {
        $result = self::runScenario('read-mode-off');

        foreach (['novamira/get-object', 'acme/report'] as $name) {
            self::assertArrayHasKey('ran', $result['run'][$name], $name);
            self::assertNull($result['refusals'][$name], $name);
        }
        self::assertNull($result['by_name']);
        self::assertSame([], $result['rules_read']);
    }

    /**
     * The instructions name the abilities the agent can use right now: one the Hub turned off is not
     * listed, and it is listed again once it is turned back on. The required ability ignores the rule.
     */
    public function testTheInstructionsListOnlyTheAbilitiesTheHubLeavesOn(): void
    {
        $result = self::runScenario('instructions');

        self::assertSame(['novamira/read-file' => true, 'novamira/get-object' => true], $result['on']);
        self::assertSame(['novamira/read-file' => true, 'novamira/get-object' => false], $result['turned_off']);
        self::assertSame(['novamira/read-file' => true, 'novamira/get-object' => true], $result['turned_on_again']);
        self::assertSame(['novamira/read-file' => true, 'novamira/get-object' => true], $result['read_mode_off']);
        self::assertSame([], $result['rules_read_when_off']);
    }

    /** @return array<array-key, mixed> */
    private static function runScenario(string $scenario): array
    {
        $root = dirname(__DIR__, levels: 2);
        $command = [PHP_BINARY, $root . '/tests/fixtures/read-mode-hub-rules-runner.php', $root, $scenario];
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
