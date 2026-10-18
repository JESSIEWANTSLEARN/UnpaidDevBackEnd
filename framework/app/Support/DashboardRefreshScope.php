<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Explicit opt-in partial reads; the existing authenticated endpoint still owns authorization. */
class DashboardRefreshScope
{
    public static function read(Request $request, array $allowed): ?array
    {
        if (!$request->has('sections')) return null;
        $raw = $request->query('sections');
        if (!is_string($raw) || strlen($raw) > 500) {
            throw ValidationException::withMessages(['sections' => 'Invalid dashboard sections.']);
        }
        $sections = array_values(array_unique(explode(',', $raw)));
        if (!$sections || array_diff($sections, $allowed)) {
            throw ValidationException::withMessages(['sections' => 'Unknown dashboard section.']);
        }
        return $sections;
    }

    public static function metrics(array $metrics, ?array $sections, array $groups): array
    {
        if ($sections === null) return $metrics;
        $keys = [];
        foreach ($sections as $section) $keys = array_merge($keys, $groups[$section] ?? []);
        return array_intersect_key($metrics, array_flip($keys));
    }
}
