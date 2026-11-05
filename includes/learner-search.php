<?php
declare(strict_types=1);

/*
 * Shared learner (venture_participants) search.
 *
 * Used by the venture portal (meal-data.php), the admin M&E report
 * (admin/meal-report.php) and its bulk-delete handler, so all three
 * match exactly the same rows for a given search term.
 */

if (!function_exists('learner_search_term')) {
    /** Normalises a raw ?q= value: trimmed, single-spaced, max 100 chars. */
    function learner_search_term($raw): string
    {
        $q = trim((string)preg_replace('/\s+/u', ' ', (string)($raw ?? '')));
        return function_exists('mb_substr') ? mb_substr($q, 0, 100) : substr($q, 0, 100);
    }
}

if (!function_exists('learner_search_clause')) {
    /**
     * Returns " AND (...)" matching $q against learner number, name, phone,
     * email and location (plus venture name when $ventureAlias is given),
     * or '' when $q is empty. Values are escaped for a LIKE literal.
     */
    function learner_search_clause(mysqli $conn, string $q, string $alias, string $ventureAlias = ''): string
    {
        if ($q === '') {
            return '';
        }

        static $existing = null;
        if ($existing === null) {
            $existing = [];
            $res = $conn->query('SHOW COLUMNS FROM venture_participants');
            if ($res instanceof mysqli_result) {
                while ($col = $res->fetch_assoc()) {
                    $existing[(string)$col['Field']] = true;
                }
            }
        }

        $alias = preg_replace('/[^a-z0-9_]/i', '', $alias);
        $like = "'%" . $conn->real_escape_string(addcslashes($q, '%_\\')) . "%'";

        $parts = [];
        foreach (['user_number', 'full_name', 'phone', 'email', 'specific_location'] as $col) {
            if (isset($existing[$col])) {
                $parts[] = "{$alias}.`{$col}` LIKE {$like}";
            }
        }

        $ventureAlias = preg_replace('/[^a-z0-9_]/i', '', $ventureAlias);
        if ($ventureAlias !== '') {
            $parts[] = "{$ventureAlias}.`name` LIKE {$like}";
        }

        return $parts ? ' AND (' . implode(' OR ', $parts) . ')' : '';
    }
}
