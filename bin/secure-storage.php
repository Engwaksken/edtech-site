<?php
declare(strict_types=1);

// CLI only. Configure DB_* and APP_ENCRYPTION_KEY in the process environment.
// php bin/secure-storage.php generate-key
// php bin/secure-storage.php migrate-secrets
// php bin/secure-storage.php backup-encrypt /private/backup.sql /private/backup.edenc
// php bin/secure-storage.php backup-decrypt /private/backup.edenc /private/restored.sql
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/backup-encryption.php';

try {
    $command = $argv[1] ?? '';
    if ($command === 'generate-key') {
        echo base64_encode(random_bytes(32)) . PHP_EOL;
    } elseif (in_array($command, ['backup-encrypt', 'backup-decrypt'], true)) {
        if (count($argv) !== 4) throw new RuntimeException('Supply an input file and a new output file.');
        site_backup_transform($argv[2], $argv[3], $command === 'backup-decrypt');
        echo "Backup operation completed. Keep the encryption key separately.\n";
    } elseif ($command === 'migrate-secrets') {
        site_encryption_key();
        require_once __DIR__ . '/../includes/config.php';
        $tables = [
            'email_settings' => ['smtp_password' => ['id', 'smtp:password:']],
            'mentor_google_tokens' => ['access_token' => ['mentor_id', 'calendar:access:'], 'refresh_token' => ['mentor_id', 'calendar:refresh:']],
        ];
        $changed = 0;
        foreach ($tables as $table => $columns) {
            $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
            $stmt->bind_param('s', $table);
            $stmt->execute();
            $exists = (bool)$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) continue;
            foreach ($columns as $column => [$idColumn, $context]) {
                // Ciphertext envelopes are longer than plaintext; widen before updating.
                $conn->query("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT NULL");
                $rows = $conn->query("SELECT `{$idColumn}` AS record_id, `{$column}` AS secret FROM `{$table}`");
                $conn->begin_transaction();
                try {
                    while ($row = $rows->fetch_assoc()) {
                        $secret = (string)($row['secret'] ?? '');
                        if ($secret === '') continue;
                        $id = (int)$row['record_id'];
                        if (str_starts_with($secret, 'enc:v1:')) {
                            site_decrypt_secret($secret, $context . $id);
                            continue;
                        }
                        $encrypted = site_encrypt_secret($secret, $context . $id);
                        $update = $conn->prepare("UPDATE `{$table}` SET `{$column}`=? WHERE `{$idColumn}`=? AND `{$column}`=?");
                        $update->bind_param('sis', $encrypted, $id, $secret);
                        $update->execute();
                        $changed += $update->affected_rows;
                        $update->close();
                    }
                    $conn->commit();
                } catch (Throwable $exception) {
                    $conn->rollback();
                    throw $exception;
                }
            }
        }
        echo "Encrypted {$changed} existing secret values. Migration is safe to rerun.\n";
    } else {
        throw new RuntimeException('Commands: generate-key, migrate-secrets, backup-encrypt, backup-decrypt.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Storage operation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
