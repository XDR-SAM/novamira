<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LegacyMcpEndpointTest extends TestCase
{
    private const ROUTE = '/mcp/mcp-adapter-default-server';

    protected function setUp(): void
    {
        require __DIR__ . '/../fixtures/legacy-mcp-stubs.php';
        require __DIR__ . '/../../vendor/novamira/mcp-adapter/autoload.php';
        require __DIR__ . '/../../includes/helpers.php';
        require __DIR__ . '/../../includes/ability-metadata.php';
        require __DIR__ . '/../../includes/mcp-mirror-server.php';
        $GLOBALS['options'] = [
            'home' => 'https://example.test',
            'novamira_ai_abilities_enabled' => '1',
            'novamira_ai_abilities_domain' => 'example.test',
        ];
    }

    private function handler(string $transport): array
    {
        // Route filtering only inspects the callback; no MCP server/session is needed here.
        $instance = (new ReflectionClass($transport))->newInstanceWithoutConstructor();
        return [
            'methods' => ['GET' => true, 'POST' => true, 'DELETE' => true],
            'callback' => [$instance, 'handle_request'],
            'permission_callback' => [$instance, 'check_permission'],
            'args' => ['test' => ['required' => false]],
        ];
    }

    private function novamiraHandler(): array
    {
        return $this->handler(\Novamira\Vendor\WP\MCP\Transport\HttpTransport::class);
    }

    private function publicHandler(): array
    {
        return $this->handler(\WP\MCP\Transport\HttpTransport::class);
    }

    public function testNovamiraWinsBothRegistrationOrdersWithoutChangingHandlersOrOtherRoutes(): void
    {
        $ours = $this->novamiraHandler();
        $public = $this->publicHandler();
        $metadata = ['namespace' => 'mcp', 'show_in_index' => false];
        $options = ['methods' => ['OPTIONS' => true], 'callback' => static fn() => null];

        foreach ([[$public, $options, $ours], [$ours, $public, $options]] as $handlers) {
            $endpoints = [
                self::ROUTE => $handlers + $metadata,
                '/mcp/novamira' => [$ours],
                '/mcp/novamira-oauth' => [$ours],
                '/mcp/rank-math-oauth' => [$public],
            ];
            $filtered = apply_filters('rest_endpoints', $endpoints);

            self::assertSame([$ours, $public, $options] + $metadata, $filtered[self::ROUTE]);
            foreach (['GET', 'POST', 'DELETE'] as $method) {
                self::assertTrue($filtered[self::ROUTE][0]['methods'][$method]);
            }
            foreach (['/mcp/novamira', '/mcp/novamira-oauth', '/mcp/rank-math-oauth'] as $route) {
                self::assertSame($endpoints[$route], $filtered[$route]);
            }
            self::assertSame($filtered, apply_filters('rest_endpoints', $filtered));
        }
    }

    public function testDisabledOrUnavailableNovamiraLeavesAllEndpointsUntouched(): void
    {
        $public = $this->publicHandler();
        $endpoints = [self::ROUTE => [$public, $this->novamiraHandler()]];
        $GLOBALS['options']['novamira_ai_abilities_enabled'] = false;
        self::assertSame($endpoints, apply_filters('rest_endpoints', $endpoints));

        $GLOBALS['options']['novamira_ai_abilities_enabled'] = '1';
        $GLOBALS['options']['novamira_ai_abilities_domain'] = 'another.test';
        self::assertSame($endpoints, apply_filters('rest_endpoints', $endpoints));

        $GLOBALS['options']['novamira_ai_abilities_domain'] = 'example.test';
        $endpoints = [self::ROUTE => [$public]];
        self::assertSame($endpoints, apply_filters('rest_endpoints', $endpoints));
        self::assertSame([], apply_filters('rest_endpoints', []));
    }

    public static function instructionModes(): iterable
    {
        yield 'normal' => [false];
        yield 'read mode' => [true];
    }

    #[DataProvider('instructionModes')]
    public function testNoticeReachesAgentContextAndDiscoveryOnlyDuringLegacyRequest(bool $readMode): void
    {
        require __DIR__ . '/../../includes/compatibility.php';
        require __DIR__ . '/../../includes/abilities/agent-context.php';
        require __DIR__ . '/../../includes/abilities/discover-abilities.php';
        $GLOBALS['test_read_mode'] = $readMode;
        add_filter('novamira_discover_abilities_instructions', static fn(string $value): string => 'Custom guidance. ' . $value);
        $baseline = novamira_agent_instructions();
        self::assertSame(!$readMode, str_contains($baseline, 'Custom guidance.'));
        self::assertStringNotContainsString('Connection notice:', $baseline);

        $request = new WP_REST_Request('POST', self::ROUTE);
        self::assertNull(apply_filters('rest_request_before_callbacks', null, $this->novamiraHandler(), $request));
        $instructions = novamira_build_agent_context()['instructions'];
        self::assertStringContainsString('Connection notice:', $instructions);
        self::assertStringContainsString('https://example.test/wp-json/mcp/novamira', $instructions);
        self::assertStringEndsWith($baseline, $instructions);
        self::assertSame($instructions, novamira_execute_discover_abilities()['novamira_instructions']);

        $error = new WP_Error('forbidden', 'Permission denied');
        self::assertSame($error, apply_filters('rest_request_after_callbacks', $error, $this->novamiraHandler(), $request));
        self::assertSame($baseline, novamira_build_agent_context()['instructions']);
    }

    public static function otherRequests(): iterable
    {
        yield 'canonical' => ['/mcp/novamira', true, true];
        yield 'OAuth' => ['/mcp/novamira-oauth', true, true];
        yield 'REST abilities' => ['/wp-abilities/v1/abilities/novamira/agent-context/run', true, true];
        yield 'public adapter on old URL' => [self::ROUTE, false, true];
        yield 'disabled' => [self::ROUTE, true, false];
    }

    #[DataProvider('otherRequests')]
    public function testOtherRequestsDoNotGetLegacyGuidance(string $route, bool $ours, bool $enabled): void
    {
        $GLOBALS['options']['novamira_ai_abilities_enabled'] = $enabled;
        $handler = $ours ? $this->novamiraHandler() : $this->publicHandler();
        $request = new WP_REST_Request('POST', $route);
        apply_filters('rest_request_before_callbacks', null, $handler, $request);
        self::assertSame('', novamira_legacy_mcp_connection_notice());
        apply_filters('rest_request_after_callbacks', null, $handler, $request);
        self::assertSame('', novamira_legacy_mcp_connection_notice());
    }

    public function testNestedRestRequestHidesThenRestoresTheOuterConnectionNotice(): void
    {
        $handler = $this->novamiraHandler();
        $outer = new WP_REST_Request('POST', self::ROUTE);
        $inner = new WP_REST_Request('GET', '/wp/v2/posts');
        apply_filters('rest_request_before_callbacks', null, $handler, $outer);
        $notice = novamira_legacy_mcp_connection_notice();
        self::assertNotSame('', $notice);
        apply_filters('rest_request_before_callbacks', null, [], $inner);
        self::assertSame('', novamira_legacy_mcp_connection_notice());
        apply_filters('rest_request_after_callbacks', null, [], $inner);
        self::assertSame($notice, novamira_legacy_mcp_connection_notice());
        apply_filters('rest_request_after_callbacks', null, $handler, $outer);
        self::assertSame('', novamira_legacy_mcp_connection_notice());
    }

    public function testRecommendedUrlUsesTheSitesRestUrl(): void
    {
        $GLOBALS['test_rest_base'] = 'https://example.test/subdirectory/?rest_route=/';
        apply_filters('rest_request_before_callbacks', null, $this->novamiraHandler(), new WP_REST_Request('POST', self::ROUTE));
        self::assertStringContainsString(
            'https://example.test/subdirectory/?rest_route=/mcp/novamira',
            novamira_legacy_mcp_connection_notice(),
        );
    }
}
