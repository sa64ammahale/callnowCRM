<?php
// Helper functions for database operations used by test scripts.
// Assumes config.php has already been required and $link is available.

if (!function_exists('dbExecute')) {
    /**
     * Execute a non‑SELECT query using prepared statements.
     * @param string $sql   Query with ? placeholders.
     * @param array  $params Parameters in order.
     * @return bool           True on success, false on failure.
     */
    function dbExecute(string $sql, array $params = []): bool {
        global $link;
        $stmt = $link->prepare($sql);
        if ($stmt === false) return false;
        if ($params) {
            // Determine types string.
            $types = '';
            $bindValues = [];
            foreach ($params as $p) {
                if (is_int($p)) $types .= 'i';
                elseif (is_float($p)) $types .= 'd';
                else $types .= 's';
                $bindValues[] = $p;
            }
            $stmt->bind_param($types, ...$bindValues);
        }
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}

if (!function_exists('dbFetchOne')) {
    /**
     * Fetch a single row as associative array.
     */
    function dbFetchOne(string $sql, array $params = []): ?array {
        global $link;
        $stmt = $link->prepare($sql);
        if ($stmt === false) return null;
        if ($params) {
            $types = '';
            $bindValues = [];
            foreach ($params as $p) {
                if (is_int($p)) $types .= 'i';
                elseif (is_float($p)) $types .= 'd';
                else $types .= 's';
                $bindValues[] = $p;
            }
            $stmt->bind_param($types, ...$bindValues);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('dbCount')) {
    /**
     * Return record count for a table with optional WHERE clause.
     */
    function dbCount(string $table, string $where = ''): int {
        $sql = 'SELECT COUNT(*) AS cnt FROM ' . tn($table);
        if ($where) $sql .= ' WHERE ' . $where;
        $row = dbFetchOne($sql);
        return $row['cnt'] ?? 0;
    }
}

if (!function_exists('dbExists')) {
    /**
     * Check if any row satisfies the condition.
     */
    function dbExists(string $table, string $cond): bool {
        $sql = 'SELECT 1 FROM ' . tn($table) . ' WHERE ' . $cond . ' LIMIT 1';
        $row = dbFetchOne($sql);
        return $row !== null;
    }
}
?>
