<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

require_once __DIR__ . '/client-store-stubs.php';

function novamira_app_passwords_status(): array
{
    return ['available' => true, 'reason' => ''];
}

function novamira_get_mcp_passwords(): array
{
    return $GLOBALS['nm_app_passwords'] ?? [];
}
