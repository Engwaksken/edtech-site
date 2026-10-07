<?php
declare(strict_types=1);



if (!function_exists('pp_column_exists')) {
    function pp_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $table  = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

        if ($table === '' || $column === '') {
            return false;
        }

        $safeColumn = $conn->real_escape_string($column);

        try {
            $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'");
            $exists = $result instanceof mysqli_result && $result->num_rows > 0;
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            return $exists;
        } catch (Throwable $e) {
            error_log('Column check failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('pp_add_column_if_missing')) {
    function pp_add_column_if_missing(
        mysqli $conn,
        string $table,
        string $column,
        string $definition
    ): void {
        if (pp_column_exists($conn, $table, $column)) {
            return;
        }

        $table  = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);

        if ($table === '' || $column === '') {
            return;
        }

        try {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (Throwable $e) {
            // 1060 = duplicate column. Another request may have added it.
            if ((int)$e->getCode() !== 1060) {
                error_log("Failed to add {$table}.{$column}: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('pp_ensure_document_folders')) {
    function pp_ensure_document_folders(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS venture_document_folders (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                venture_id  INT UNSIGNED NOT NULL,
                parent_id   INT UNSIGNED NULL,
                folder_name VARCHAR(255) NOT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_venture (venture_id),
                KEY idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        /*
         * Keep these migrations idempotent. Existing production databases
         * may already have one or both columns.
         */
        pp_add_column_if_missing(
            $conn,
            'venture_documents',
            'folder_id',
            'INT UNSIGNED NULL AFTER `venture_id`'
        );

        pp_add_column_if_missing(
            $conn,
            'venture_documents',
            'other_category_name',
            'VARCHAR(120) NULL AFTER `category`'
        );

        // Add the folder index only if it does not already exist.
        try {
            $indexes = $conn->query("SHOW INDEX FROM `venture_documents` WHERE Key_name = 'idx_folder'");
            $hasIndex = $indexes instanceof mysqli_result && $indexes->num_rows > 0;
            if ($indexes instanceof mysqli_result) {
                $indexes->free();
            }
            if (!$hasIndex) {
                $conn->query("ALTER TABLE `venture_documents` ADD KEY `idx_folder` (`folder_id`)");
            }
        } catch (Throwable $e) {
            // 1061 = duplicate key name.
            if ((int)$e->getCode() !== 1061) {
                error_log('Failed to ensure venture_documents.idx_folder: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('pp_folder_breadcrumb')) {
    function pp_folder_breadcrumb(mysqli $conn, int $venture_id, ?int $folder_id): array
    {
        $trail = [];
        $guard = 0;
        $seen  = [];

        while ($folder_id !== null && $folder_id > 0 && $guard < 50) {
            if (isset($seen[$folder_id])) {
                break;
            }
            $seen[$folder_id] = true;

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

            array_unshift($trail, $row);
            $folder_id = $row['parent_id'] !== null && (int)$row['parent_id'] > 0
                ? (int)$row['parent_id']
                : null;
            $guard++;
        }

        return $trail;
    }
}

if (!function_exists('pp_resolve_folder_path')) {
    function pp_resolve_folder_path(
        mysqli $conn,
        int $venture_id,
        ?int $root_folder_id,
        string $path
    ): ?int {
        $parent = ($root_folder_id !== null && $root_folder_id > 0)
            ? $root_folder_id
            : null;

        $normalised = str_replace('\\', '/', $path);
        $segments = array_values(array_filter(
            array_map('trim', explode('/', $normalised)),
            static fn($s) => $s !== '' && $s !== '.' && $s !== '..'
        ));

        foreach ($segments as $segment) {
            $segment = preg_replace('/[\x00-\x1F\x7F]/u', '', $segment) ?? '';
            $segment = trim(mb_substr($segment, 0, 255));

            if ($segment === '') {
                continue;
            }

            if ($parent === null) {
                $find = $conn->prepare("
                    SELECT id
                    FROM venture_document_folders
                    WHERE venture_id = ?
                      AND (parent_id IS NULL OR parent_id = 0)
                      AND folder_name = ?
                    LIMIT 1
                ");
                $find->bind_param('is', $venture_id, $segment);
            } else {
                $find = $conn->prepare("
                    SELECT id
                    FROM venture_document_folders
                    WHERE venture_id = ?
                      AND parent_id = ?
                      AND folder_name = ?
                    LIMIT 1
                ");
                $find->bind_param('iis', $venture_id, $parent, $segment);
            }

            $find->execute();
            $row = $find->get_result()->fetch_assoc();
            $find->close();

            if ($row) {
                $parent = (int)$row['id'];
                continue;
            }

            if ($parent === null) {
                $ins = $conn->prepare("
                    INSERT INTO venture_document_folders
                        (venture_id, parent_id, folder_name)
                    VALUES (?, NULL, ?)
                ");
                $ins->bind_param('is', $venture_id, $segment);
            } else {
                $ins = $conn->prepare("
                    INSERT INTO venture_document_folders
                        (venture_id, parent_id, folder_name)
                    VALUES (?, ?, ?)
                ");
                $ins->bind_param('iis', $venture_id, $parent, $segment);
            }

            $ins->execute();
            $parent = (int)$conn->insert_id;
            $ins->close();
        }

        return $parent;
    }
}

if (!function_exists('pp_folder_is_descendant')) {
   
    function pp_folder_is_descendant(
        mysqli $conn,
        int $venture_id,
        int $folderId,
        int $possibleAncestorId
    ): bool {
        $current = $folderId;
        $guard   = 0;
        $seen    = [];

        while ($current > 0 && $guard < 50) {
            if ($current === $possibleAncestorId) {
                return true;
            }
            if (isset($seen[$current])) {
                break;
            }
            $seen[$current] = true;

            $stmt = $conn->prepare("
                SELECT parent_id
                FROM venture_document_folders
                WHERE id = ? AND venture_id = ?
                LIMIT 1
            ");
            $stmt->bind_param('ii', $current, $venture_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row || $row['parent_id'] === null || (int)$row['parent_id'] <= 0) {
                break;
            }

            $current = (int)$row['parent_id'];
            $guard++;
        }

        return false;
    }
}

if (!function_exists('pp_folder_exists')) {
    function pp_folder_exists(mysqli $conn, int $venture_id, int $folder_id): bool
    {
        if ($folder_id <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM venture_document_folders
            WHERE id = ? AND venture_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $folder_id, $venture_id);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $exists;
    }
}
