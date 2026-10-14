<?php

// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * What the Abilities Hub and Features screens call that the read-mode runners do not already stub: enough
 * for a request to get past read mode's check to the validation that follows it.
 */

namespace Novamira\Features {
    /** A feature manager that knows no feature. */
    function features(): object
    {
        return new class {
            public function definition(string $id): ?object
            {
                return null;
            }

            public function feature_for_ability(string $ability_name, string $category = ''): ?object
            {
                return null;
            }

            public function is_ability_active(string $ability_name, string $category = ''): bool
            {
                return true;
            }

            public function is_active(string $feature_id): bool
            {
                return true;
            }
        };
    }
}

namespace {
    function sanitize_text_field(string $text): string
    {
        return trim($text);
    }
}
