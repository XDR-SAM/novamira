<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GutenbergQueueClaimTest extends TestCase
{
    public function testExpiredRunningBatchIsReclaimedWithoutStatusPolling(): void
    {
        $result = $this->scenario('expired');
        self::assertFalse($result['response']['done']);
        self::assertSame(10, $result['response']['claim']['batch']['batch_id']);
        self::assertSame('running', $result['status']);
        self::assertNotSame('previous-owner', $result['owner']);
        self::assertTrue($result['valid_lease']);
    }

    public function testValidRunningBatchIsSkippedAsNotClaimable(): void
    {
        $result = $this->scenario('active');
        self::assertSame(['done' => true], $result['response']);
        self::assertSame('gutenberg_batch_not_claimable', $result['direct_claim_error']);
        self::assertSame('running', $result['status']);
        self::assertSame('previous-owner', $result['owner']);
        self::assertTrue($result['valid_lease']);
    }

    public function testReadyBatchPrecedesNewerFailedBatchesBeyondCandidateLimit(): void
    {
        $result = $this->scenario('priority');
        self::assertSame(10, $result['response']['claim']['batch']['batch_id']);
        self::assertSame('failed', $result['newer_status']);
    }

    public function testFailedBatchStillRetriesWhenNoReadyWorkExists(): void
    {
        $result = $this->scenario('failed');
        self::assertFalse($result['response']['done']);
        self::assertSame(10, $result['response']['claim']['batch']['batch_id']);
        self::assertTrue($result['valid_lease']);
    }

    /** @return array<string, mixed> */
    private function scenario(string $scenario): array
    {
        $script = <<<'PHP'
            define('ABSPATH', '/');
            class WP_Error {
                public function __construct(private string $code, string $message, mixed $data = null) {}
                public function get_error_code(): string { return $this->code; }
            }
            class WP_REST_Response {
                public function __construct(public array $data) {}
            }
            class WP_Post {
                public string $post_type = 'novamira_gb_change';
                public string $post_title = 'Queue batch';
                public string $post_excerpt = '';
                public string $post_date_gmt = '2026-01-01 00:00:00';
                public int $post_parent = 0;
                public function __construct(public int $ID) {}
            }
            function add_action(...$args): void {}
            function add_filter(...$args): void {}
            function __(string $text, string $domain = ''): string { return $text; }
            function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
            function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
            function wp_generate_uuid4(): string { return uniqid('owner-', true); }
            function get_post(int $id): ?WP_Post { return $GLOBALS['posts'][$id] ?? null; }
            function get_post_meta(int $post_id, string $key, bool $single = false): mixed {
                return $GLOBALS['meta'][$post_id][$key] ?? '';
            }
            function update_post_meta(int $post_id, string $key, mixed $meta_value): bool {
                $GLOBALS['meta'][$post_id][$key] = $meta_value;
                return true;
            }
            function delete_post_meta(int $post_id, string $key): bool {
                unset($GLOBALS['meta'][$post_id][$key]);
                return true;
            }
            function add_option(string $option, mixed $value, mixed $deprecated = '', mixed $autoload = null): bool {
                if (isset($GLOBALS['options'][$option])) { return false; }
                $GLOBALS['options'][$option] = $value;
                return true;
            }
            function get_option(string $option, mixed $default_value = false): mixed {
                return $GLOBALS['options'][$option] ?? $default_value;
            }
            function delete_option(string $option): bool {
                unset($GLOBALS['options'][$option]);
                return true;
            }
            function admin_url(string $path): string { return 'https://example.test/wp-admin/' . $path; }
            function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
            function get_posts(array $args): array {
                $posts = array_filter($GLOBALS['posts'], static function (WP_Post $post) use ($args): bool {
                    if (isset($args['post_parent']) && $post->post_parent !== $args['post_parent']) { return false; }
                    foreach ($args['meta_query'] ?? [] as $query) {
                        $actual = get_post_meta($post->ID, $query['key'], true);
                        $values = (array) $query['value'];
                        if (!in_array($actual, $values, true)) { return false; }
                    }
                    return true;
                });
                usort($posts, static fn(WP_Post $a, WP_Post $b): int =>
                    $args['order'] === 'DESC' ? $b->ID <=> $a->ID : $a->ID <=> $b->ID);
                return $args['posts_per_page'] < 0 ? $posts : array_slice($posts, 0, $args['posts_per_page']);
            }
            require $argv[1] . '/includes/abilities/gutenberg/bootstrap.php';
            require $argv[1] . '/includes/abilities/gutenberg/rest.php';
            eval('namespace Novamira\\Abilities\\Gutenberg; function finalizer_runtime_status(?\\WP_Post $batch = null): array { return ["online" => false]; }');

            $gb = 'Novamira\\Abilities\\Gutenberg\\';
            $GLOBALS['posts'] = [10 => new WP_Post(10)];
            $GLOBALS['meta'] = [];
            $GLOBALS['options'] = [];
            $scenario = $argv[2];
            update_post_meta(10, constant($gb . 'META_KIND'), 'batch');
            ($gb . 'set_status')(10, match ($scenario) {
                'priority' => 'ready',
                'failed' => 'failed',
                default => 'running',
            });
            ($gb . 'set_lease')(10, 'previous-owner');
            if ($scenario === 'expired') {
                update_post_meta(10, constant($gb . 'META_LEASE_EXPIRES_AT'), gmdate('Y-m-d H:i:s', time() - 10));
            }
            if ($scenario === 'priority') {
                // More failed batches than the claim query's limit must not hide ready work.
                for ($id = 11; $id <= 65; ++$id) {
                    $GLOBALS['posts'][$id] = new WP_Post($id);
                    update_post_meta($id, constant($gb . 'META_KIND'), 'batch');
                    ($gb . 'set_status')($id, 'failed');
                }
            }
            $response = ($gb . 'rest_claim_next_batch')();
            $direct = $scenario === 'active' ? ($gb . 'claim_batch')(10) : null;
            $owner = get_post_meta(10, constant($gb . 'META_LEASE_OWNER'), true);
            echo json_encode([
                'response' => $response->data,
                'status' => ($gb . 'status')(10),
                'owner' => $owner,
                'valid_lease' => ($gb . 'lease_is_valid')(10, $owner),
                'newer_status' => ($gb . 'status')(65),
                'direct_claim_error' => $direct instanceof WP_Error ? $direct->get_error_code() : null,
            ], JSON_THROW_ON_ERROR);
            PHP;

        $command = sprintf(
            '%s -r %s %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg(dirname(__DIR__, levels: 2)),
            escapeshellarg($scenario),
        );
        $output = (string) shell_exec($command);
        $result = json_decode($output, associative: true);
        self::assertIsArray($result, $output);
        return $result;
    }
}
