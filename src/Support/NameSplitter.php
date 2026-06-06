<?php

namespace Bausteln\SnipeitOidc\Support;

/**
 * Split a combined display name into [first, last].
 *
 * Heuristic: the first whitespace-delimited token is the first name, the
 * remainder is the last name. Keeps multi-word surnames ("von Monn",
 * "van der Berg") intact — the common case in the DACH/European context.
 */
class NameSplitter
{
    /**
     * @return array{0: string, 1: string} [$first, $last]
     */
    public static function split(string $full): array
    {
        // preg_split is typed array|false in static-analysis stubs; the `?: []`
        // narrows it to array for the checks below. With this fixed, valid
        // pattern it never actually returns false at runtime.
        $parts = preg_split('/\s+/', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return ['', ''];
        }
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }
}
