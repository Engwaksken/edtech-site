<?php
declare(strict_types=1);

/**
 * Shared helpers for the admin "Document Vault" folder feature.
 * Included by both venture-docs.php and includes/process-ventures.php.
 *
 * NOTE: MySQLi bind_param('i', $null_value) sends 0, not SQL NULL.
 * Every function below uses separate query branches for null vs non-null
 * parent_id / folder_id to guarantee correct IS NULL / = ? semantics.
 */

if (!function_exists('vdf_column_exists')) {
    function vdf_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
        $stmt->close();

        return $exists;
    }
}

if (!function_exists('ven_ensure_document_folders')) {
    /**
     * Lazily create the folders table and the folder_id column on
     * venture_documents, matching this codebase's cPanel-safe migration
     * style (no manual SQL migration required).
     */
    function ven_ensure_document_folders(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS venture_document_folders (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                venture_id INT NOT NULL,
                parent_id INT UNSIGNED NULL DEFAULT NULL,
                folder_name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_venture (venture_id),
                KEY idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        if (!vdf_column_exists($conn, 'venture_documents', 'folder_id')) {
            $conn->query("
                ALTER TABLE venture_documents
                ADD COLUMN folder_id INT UNSIGNED NULL DEFAULT NULL AFTER doc_group
            ");
            $conn->query("ALTER TABLE venture_documents ADD KEY idx_folder (folder_id)");
        }
    }
}

if (!function_exists('ven_folder_breadcrumb')) {
    /**
     * Returns the root-first chain of folders leading to $folder_id.
     */
    function ven_folder_breadcrumb(mysqli $conn, int $venture_id, int $folder_id): array
    {
        $chain = [];
        $guard = 0;

        while ($folder_id > 0 && $guard < 50) {
            $guard++;

            $stmt = $conn->prepare("
                SELECT id, parent_id, folder_name
                FROM venture_document_folders
                WHERE id = ? AND venture_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('ii', $folder_id, $venture_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                break;
            }

            array_unshift($chain, $row);
            $folder_id = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
        }

        return $chain;
    }
}

if (!function_exists('ven_folder_is_descendant')) {
    /**
     * True if $possible_ancestor_id IS $folder_id or lies somewhere below it
     * in the tree. Used to stop a folder being dropped into its own child.
     */
    function ven_folder_is_descendant(mysqli $conn, int $venture_id, int $folder_id, int $possible_ancestor_id): bool
    {
        if ($folder_id === $possible_ancestor_id) {
            return true;
        }

        $stmt = $conn->prepare("
            SELECT id FROM venture_document_folders
            WHERE parent_id = ? AND venture_id = ?
        ");
        $stmt->bind_param('ii', $folder_id, $venture_id);
        $stmt->execute();
        $children = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($children as $child) {
            if (ven_folder_is_descendant($conn, $venture_id, (int)$child['id'], $possible_ancestor_id)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ven_resolve_folder_path')) {
    /**
     * Resolves (creating as needed) a slash-separated folder path such as
     * "Legal/Contracts/2024" under $root_folder_id, returning the id of the
     * deepest folder. Used when a whole OS folder is dragged/dropped in.
     */
    function ven_resolve_folder_path(mysqli $conn, int $venture_id, int $root_folder_id, string $path): int
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            return $root_folder_id;
        }

        $parent = $root_folder_id;

        foreach (explode('/', $path) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            /* --- look for existing child -------------------------------- */
            if ($parent > 0) {
                $stmt = $conn->prepare("
                    SELECT id FROM venture_document_folders
                    WHERE venture_id = ? AND parent_id = ? AND folder_name = ?
                    LIMIT 1
                ");
                $stmt->bind_param('iis', $venture_id, $parent, $part);
            } else {
                $stmt = $conn->prepare("
                    SELECT id FROM venture_document_folders
                    WHERE venture_id = ? AND parent_id IS NULL AND folder_name = ?
                    LIMIT 1
                ");
                $stmt->bind_param('is', $venture_id, $part);
            }

            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($found) {
                $parent = (int)$found['id'];
                continue;
            }

            /* --- create the folder -------------------------------------- */
            if ($parent > 0) {
                $stmt = $conn->prepare("
                    INSERT INTO venture_document_folders (venture_id, parent_id, folder_name)
                    VALUES (?, ?, ?)
                ");
                $stmt->bind_param('iis', $venture_id, $parent, $part);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO venture_document_folders (venture_id, parent_id, folder_name)
                    VALUES (?, NULL, ?)
                ");
                $stmt->bind_param('is', $venture_id, $part);
            }

            $stmt->execute();
            $parent = (int)$stmt->insert_id;
            $stmt->close();
        }

        return $parent;
    }
}