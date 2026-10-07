<?php
declare(strict_types=1);
require_once __DIR__ . '/encryption.php';

function site_stream_read($stream, int $length): string
{
    $data = '';
    while (strlen($data) < $length && !feof($stream)) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === false) {
            throw new RuntimeException('Backup read failed.');
        }
        $data .= $chunk;
    }
    if (strlen($data) !== $length) {
        throw new RuntimeException('Backup is truncated.');
    }
    return $data;
}

function site_stream_write($stream, string $data): void
{
    while ($data !== '') {
        $written = fwrite($stream, $data);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Backup write failed.');
        }
        $data = substr($data, $written);
    }
}

function site_backup_transform(string $source, string $destination, bool $decrypt = false): void
{
    $key = site_encryption_key();
    if (!is_file($source) || file_exists($destination) || !is_dir(dirname($destination))) {
        throw new RuntimeException('Source must exist and destination must be a new file in an existing directory.');
    }
    $parent = realpath(dirname($destination));
    $publicRoot = realpath(dirname(__DIR__));
    $comparisonParent = PHP_OS_FAMILY === 'Windows' ? strtolower($parent) : $parent;
    $comparisonRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($publicRoot) : $publicRoot;
    if ($comparisonParent === $comparisonRoot || str_starts_with($comparisonParent, $comparisonRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Backup output must be outside the public document root.');
    }
    $input = fopen($source, 'rb');
    $temporary = $destination . '.partial.' . bin2hex(random_bytes(8));
    $output = fopen($temporary, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw new RuntimeException('Could not open backup files.');
    }
    chmod($temporary, 0600);
    try {
        $magic = "EDTECH-BACKUP-v1\n";
        $fileId = $decrypt ? '' : random_bytes(16);
        if ($decrypt) {
            if (site_stream_read($input, strlen($magic)) !== $magic) {
                throw new RuntimeException('Unknown encrypted backup format.');
            }
            $fileId = site_stream_read($input, 16);
        } else {
            site_stream_write($output, $magic . $fileId);
        }
        $sequence = 0;
        while (true) {
            if ($decrypt) {
                $flag = ord(site_stream_read($input, 1));
                $length = unpack('N', site_stream_read($input, 4))[1];
                if ($flag > 1 || $length < 28 || $length > 1048576 + 28) {
                    throw new RuntimeException('Invalid encrypted backup frame.');
                }
                $frame = site_stream_read($input, $length);
                $aad = 'backup:v1:' . bin2hex($fileId) . ':' . $sequence . ':' . $flag;
                $chunk = openssl_decrypt(substr($frame, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                    substr($frame, 0, 12), substr($frame, 12, 16), $aad);
                if ($chunk === false) {
                    throw new RuntimeException('Backup authentication failed.');
                }
                if ($flag === 1) {
                    if ($chunk !== '' || fread($input, 1) !== '') {
                        throw new RuntimeException('Invalid backup ending.');
                    }
                    break;
                }
                site_stream_write($output, $chunk);
            } else {
                $chunk = fread($input, 1048576);
                if ($chunk === false) throw new RuntimeException('Backup read failed.');
                $flag = $chunk === '' && feof($input) ? 1 : 0;
                $nonce = random_bytes(12);
                $tag = '';
                $aad = 'backup:v1:' . bin2hex($fileId) . ':' . $sequence . ':' . $flag;
                $cipher = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
                if ($cipher === false) throw new RuntimeException('Backup encryption failed.');
                $frame = $nonce . $tag . $cipher;
                site_stream_write($output, chr($flag) . pack('N', strlen($frame)) . $frame);
                if ($flag === 1) break;
            }
            $sequence++;
        }
        fflush($output);
        fclose($input);
        fclose($output);
        if (!rename($temporary, $destination)) {
            throw new RuntimeException('Could not publish completed backup.');
        }
    } catch (Throwable $exception) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        if (is_file($temporary)) unlink($temporary);
        throw $exception;
    }
}
