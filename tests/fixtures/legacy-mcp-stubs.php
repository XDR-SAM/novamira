<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\Skills\Sources {
    function discoverable(string $mode): array
    {
        return [];
    }
}

namespace {
    // Loaded only inside isolated PHPUnit processes, never in the shared test process.
    require __DIR__ . '/read-mode-stubs.php';

    class WP_REST_Request
    {
        public function __construct(private string $method = 'POST', private string $route = '') {}

        public function get_route(): string
        {
            return $this->route;
        }
    }

    function rest_url(string $path = ''): string
    {
        return ($GLOBALS['test_rest_base'] ?? 'https://example.test/wp-json/') . $path;
    }

    function novamira_is_read_mode(): bool
    {
        return $GLOBALS['test_read_mode'] ?? false;
    }

    function novamira_read_mode_instructions(): string
    {
        return 'Read-mode instructions.';
    }

    function wp_get_current_user(): object
    {
        return (object) ['ID' => 7];
    }

    function get_bloginfo(string $show = ''): string
    {
        return '6.9.4';
    }

    function get_locale(): string
    {
        return 'en_US';
    }

    function wp_get_theme(): object
    {
        return new class {
            public function get(string $key): string
            {
                return 'Test theme';
            }

            public function get_template(): string
            {
                return 'test-theme';
            }

            public function get_stylesheet(): string
            {
                return 'test-theme';
            }
        };
    }
}
