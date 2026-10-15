<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * gutenberg-get-content returns a content_hash of the live post_content, and gutenberg-add-pending-change
 * refuses a change whose expected_content_hash no longer matches the target, before any batch or item is
 * created. The Gutenberg ability files are loaded in an isolated PHP process with in-memory post storage;
 * wp_insert_post() returns a sentinel error, so reaching it proves the guard let the change through.
 */
final class GutenbergStaleReadGuardTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $result = null;

    public function testGetContentReturnsTheLiveContentHashAndModifiedTime(): void
    {
        $result = $this->scenarios();

        self::assertSame(hash('sha256', $result['original_content']), $result['read']['content_hash']);
        self::assertSame('2026-10-01 08:00:00', $result['read']['modified_gmt']);
        self::assertArrayHasKey('content_hash', $result['get_content_output_schema']);
        self::assertArrayHasKey('modified_gmt', $result['get_content_output_schema']);
    }

    public function testMatchingHashLetsTheChangeThrough(): void
    {
        self::assertSame('test_persist_sentinel', $this->scenarios()['match']['code']);
    }

    public function testMatchingHashIgnoresCaseAndSurroundingWhitespace(): void
    {
        self::assertSame('test_persist_sentinel', $this->scenarios()['match_uppercase']['code']);
    }

    public function testOmittedOrEmptyHashKeepsThePreviousBehavior(): void
    {
        $result = $this->scenarios();

        self::assertSame('test_persist_sentinel', $result['omitted']['code']);
        self::assertSame('test_persist_sentinel', $result['empty']['code']);
    }

    public function testMismatchedHashIsRefusedBeforeAnythingIsCreated(): void
    {
        $result = $this->scenarios();
        $error = $result['mismatch'];

        self::assertSame('gutenberg_target_changed_since_read', $error['code']);
        self::assertSame(409, $error['data']['status']);
        self::assertSame($result['read']['content_hash'], $error['data']['expected_content_hash']);
        self::assertSame(hash('sha256', $result['edited_content']), $error['data']['current_content_hash']);
        self::assertSame('2026-10-02 09:30:00', $error['data']['modified_gmt']);
        self::assertStringContainsString('Page 1 changed since it was read', $error['message']);
        self::assertStringContainsString('novamira/gutenberg-get-content', $error['message']);
        self::assertStringContainsString('keeping the edits made since your read', $error['message']);
        self::assertSame(0, $result['mismatch_insert_calls']);
    }

    public function testAbilityTextsAndSkillDescribeTheGuard(): void
    {
        $result = $this->scenarios();
        $skill = (string) file_get_contents(
            dirname(__DIR__, 2) . '/includes/skills/built-in/gutenberg-edit-content.md',
        );

        self::assertSame('string', $result['add_pending_input_schema']['expected_content_hash']['type']);
        self::assertStringContainsString('expected_content_hash', $result['add_pending_description']);
        self::assertStringContainsString('gutenberg_target_changed_since_read', $result['add_pending_instructions']);
        self::assertStringContainsString('expected_content_hash', $result['get_content_instructions']);
        self::assertStringContainsString('`expected_content_hash`', $skill);
        self::assertStringContainsString('`gutenberg_target_changed_since_read`', $skill);
    }

    /** @return array<string, mixed> */
    private function scenarios(): array
    {
        if (self::$result === null) {
            self::$result = $this->runIntegrationScript();
        }

        return self::$result;
    }

    /** @return array<string, mixed> */
    private function runIntegrationScript(): array
    {
        $root = dirname(__DIR__, levels: 2);
        $script = <<<'PHP'
            define('ABSPATH', '/');
            $GLOBALS['novamira_test_abilities'] = [];
            $GLOBALS['novamira_test_insert_calls'] = 0;

            class WP_Error {
                public function __construct(
                    private string $code = '',
                    private string $message = '',
                    private mixed $data = null,
                ) {}
                public function get_error_code(): string { return $this->code; }
                public function get_error_message(): string { return $this->message; }
                public function get_error_data(): mixed { return $this->data; }
            }
            class WP_Post {
                public function __construct(
                    public int $ID = 1,
                    public string $post_type = 'page',
                    public string $post_title = 'Target',
                    public string $post_content = '',
                    public int $post_parent = 0,
                    public string $post_date_gmt = '2026-01-01 00:00:00',
                    public string $post_excerpt = '',
                    public string $post_modified_gmt = '2026-01-01 00:00:00',
                ) {}
            }
            $original = "<!-- wp:paragraph -->\n<p>Agent read this.</p>\n<!-- /wp:paragraph -->";
            $edited = "<!-- wp:paragraph -->\n<p>The user edited this by hand.</p>\n<!-- /wp:paragraph -->";
            $GLOBALS['novamira_test_posts'] = [
                1 => new WP_Post(1, 'page', 'Target', $original, 0, '2026-01-01 00:00:00', '', '2026-10-01 08:00:00'),
            ];

            function __(string $text, string $domain = 'default'): string { return $text; }
            function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
            function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void {}
            function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void {}
            function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
            function wp_register_ability(string $name, array $args): void {
                $GLOBALS['novamira_test_abilities'][$name] = $args;
            }
            function get_post(mixed $post_id): ?WP_Post { return $GLOBALS['novamira_test_posts'][(int) $post_id] ?? null; }
            function get_posts(array $args): array { return []; }
            function get_post_meta(int $post_id, string $key, bool $single = false): mixed { return ''; }
            function update_post_meta(int $post_id, string $key, mixed $value): bool { return true; }
            function sanitize_text_field(string $value): string { return trim($value); }
            function wp_strip_all_tags(string $value): string { return strip_tags($value); }
            function parse_blocks(string $content): array {
                return [['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $content]];
            }
            function wp_insert_post(array $data, bool $wp_error = false): WP_Error {
                $GLOBALS['novamira_test_insert_calls']++;
                return new WP_Error('test_persist_sentinel', 'Persistence is out of scope for this test.');
            }

            require $argv[1] . '/includes/abilities/gutenberg/bootstrap.php';
            require $argv[1] . '/includes/abilities/gutenberg/get-content.php';
            require $argv[1] . '/includes/abilities/gutenberg/add-pending-change.php';
            // The finalizer runtime lives in runtime.php, which is not loaded here.
            eval('namespace Novamira\\Abilities\\Gutenberg; function finalizer_runtime_status(?\\WP_Post $batch = null): array { return ["online" => false, "can_finalize_batch" => false]; } function finalizer_runtime_startup_instruction(): string { return ""; }');

            $shape = static function (mixed $value): mixed {
                if ($value instanceof WP_Error) {
                    return [
                        'code' => $value->get_error_code(),
                        'message' => $value->get_error_message(),
                        'data' => $value->get_error_data(),
                    ];
                }
                return $value;
            };
            $gb = 'Novamira\Abilities\Gutenberg\\';
            $add = static fn(array $extra): mixed => $shape(($gb . 'gutenberg_add_pending_change')([
                'target_id' => 1,
                'block_spec' => [['name' => 'core/paragraph', 'attributes' => ['content' => 'New'], 'innerBlocks' => []]],
            ] + $extra));

            $out = [
                'original_content' => $original,
                'edited_content' => $edited,
            ];
            $read = ($gb . 'gutenberg_get_content')(['target_id' => 1]);
            $out['read'] = [
                'content_hash' => $read['content_hash'],
                'modified_gmt' => $read['modified_gmt'],
            ];

            $out['match'] = $add(['expected_content_hash' => $read['content_hash']]);
            $out['match_uppercase'] = $add(['expected_content_hash' => ' ' . strtoupper($read['content_hash']) . "\n"]);
            $out['omitted'] = $add([]);
            $out['empty'] = $add(['expected_content_hash' => '']);

            $GLOBALS['novamira_test_posts'][1]->post_content = $edited;
            $GLOBALS['novamira_test_posts'][1]->post_modified_gmt = '2026-10-02 09:30:00';
            $GLOBALS['novamira_test_insert_calls'] = 0;
            $out['mismatch'] = $add(['expected_content_hash' => $read['content_hash']]);
            $out['mismatch_insert_calls'] = $GLOBALS['novamira_test_insert_calls'];

            $get_content = $GLOBALS['novamira_test_abilities']['novamira/gutenberg-get-content'];
            $add_pending = $GLOBALS['novamira_test_abilities']['novamira/gutenberg-add-pending-change'];
            $out['get_content_output_schema'] = $get_content['output_schema']['properties'];
            $out['get_content_instructions'] = $get_content['meta']['annotations']['instructions'];
            $out['add_pending_input_schema'] = $add_pending['input_schema']['properties'];
            $out['add_pending_description'] = $add_pending['description'];
            $out['add_pending_instructions'] = $add_pending['meta']['annotations']['instructions'];

            echo json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            PHP;

        $command = sprintf(
            '%s -r %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($root),
        );
        $output = (string) shell_exec($command);
        $decoded = json_decode($output, associative: true);
        self::assertIsArray($decoded, $output);

        return $decoded;
    }
}
