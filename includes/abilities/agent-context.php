<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

wp_register_ability('novamira/agent-context', [
    'label' => __('Agent Context', domain: 'novamira'),
    // Read mode offers no skills, so its description does not mention them.
    'description' => function_exists('novamira_is_read_mode') && novamira_is_read_mode()
        ? __('Return transport-neutral Novamira read-mode guidance and environment.', domain: 'novamira')
        : __('Return transport-neutral Novamira site guidance, environment, and skill summaries.', domain: 'novamira'),
    'category' => 'context',
    'output_schema' => [
        'type' => 'object',
        'properties' => [
            'server' => ['type' => 'object'],
            'instructions' => ['type' => 'string'],
            'skills' => ['type' => 'array', 'items' => ['type' => 'object']],
            'environment' => ['type' => 'object'],
            'read_mode' => [
                'type' => 'object',
                'properties' => ['active' => ['type' => 'boolean']],
                'required' => ['active'],
            ],
        ],
        'required' => ['server', 'instructions', 'skills', 'environment', 'read_mode'],
    ],
    'execute_callback' => 'novamira_build_agent_context',
    'permission_callback' => 'novamira_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'annotations' => [
            'readonly' => true,
            'destructive' => false,
            'idempotent' => true,
        ],
        'mcp' => ['public' => true, 'type' => 'tool'],
    ],
]);

/**
 * Reuse the same instruction and skill source/filter pipeline as existing integrations.
 *
 * @return array{
 *     server: array<string, mixed>,
 *     instructions: string,
 *     skills: list<array{slug: string, description: string, source: string}>,
 *     environment: array{wordpress_version: string, php_version: string, locale: string},
 *     read_mode: array{active: bool}
 * }
 */
function novamira_build_agent_context(): array
{
    $instructions = function_exists('novamira_agent_instructions')
        ? novamira_agent_instructions()
        : (string) apply_filters('novamira_discover_abilities_instructions', novamira_build_server_instructions());

    // Read mode offers no skills, so their sources and loaders are not consulted at all.
    $read_mode = function_exists('novamira_is_read_mode') && novamira_is_read_mode();
    $skills = [];
    foreach ($read_mode ? [] : \Novamira\Skills\Sources\discoverable('agentic') as $skill) {
        $skills[] = [
            'slug' => (string) ($skill['slug'] ?? ''),
            'description' => (string) ($skill['description'] ?? ''),
            'source' => (string) ($skill['source'] ?? ''),
        ];
    }

    return [
        'server' => novamira_server_compatibility(),
        'instructions' => $instructions,
        'skills' => $skills,
        'environment' => [
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'locale' => get_locale(),
        ],
        // Whether read mode is on, for clients such as Novamira HQ: the instructions say so only as translated text.
        'read_mode' => ['active' => $read_mode],
    ];
}
