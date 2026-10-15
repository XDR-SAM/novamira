<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TroubleshootAdminStorageTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/fixtures/troubleshoot-admin-stubs.php';
        require_once __DIR__ . '/../../includes/oauth/bootstrap.php';
        require_once __DIR__ . '/../../includes/oauth/schema.php';
        require_once __DIR__ . '/../../includes/troubleshoot/admin.php';
        require_once __DIR__ . '/../../includes/troubleshoot/notice.php';
        require_once __DIR__ . '/../../includes/troubleshoot/checks.php';
        nm_test_reset_state();
    }

    public function testFreshInstallDoesNotQueryNonexistentOAuthTables(): void
    {
        $GLOBALS['nm_abilities_enabled'] = false;
        $GLOBALS['nm_transport_allowed'] = false;
        self::assertSame('', Novamira\Troubleshoot\Admin\detect_connection_method());
        self::assertSame([], Novamira\Troubleshoot\Notice\regressions());
        self::assertSame([], $GLOBALS['wpdb']->reads);
        self::assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testExistingConnectionsAreDetectedEvenWhenAbilitiesAreOff(): void
    {
        Novamira\OAuth\Schema\maybe_install();
        nm_test_store_client('existing-client');
        $GLOBALS['wpdb']->rows[nm_test_clients_table()][0]['last_used_at'] = gmdate('Y-m-d H:i:s');
        $GLOBALS['nm_abilities_enabled'] = false;
        $GLOBALS['nm_transport_allowed'] = false;
        nm_test_forget_schema_activity();
        self::assertSame('oauth', Novamira\Troubleshoot\Admin\detect_connection_method());
        self::assertCount(1, Novamira\Troubleshoot\Notice\regressions());
        self::assertNotEmpty($GLOBALS['wpdb']->reads);
    }

    public function testLastAuthenticatedMethodStillTakesPrecedence(): void
    {
        $GLOBALS['nm_options']['novamira_mcp_last_request'] = ['oauth' => 100, 'password' => 200];
        self::assertSame('password', Novamira\Troubleshoot\Admin\detect_connection_method());
        self::assertSame([], $GLOBALS['wpdb']->reads);
    }

    public function testApplicationPasswordsStillWorkWithoutOAuthStorage(): void
    {
        $GLOBALS['nm_app_passwords'] = [['name' => 'Existing connection']];
        self::assertSame('password', Novamira\Troubleshoot\Admin\detect_connection_method());
        self::assertSame([], $GLOBALS['wpdb']->reads);
    }

    public function testRecordedSchemaWithMissingTablesIsStillReportedAsBroken(): void
    {
        $GLOBALS['nm_options']['novamira_oauth_schema_version'] = Novamira\OAuth\Schema\CURRENT_SCHEMA_VERSION;
        $GLOBALS['nm_dbdelta_cannot_create'] = [nm_test_clients_table()];
        self::assertSame('fail', Novamira\Troubleshoot\Checks\check_schema()['status']);
    }
}
