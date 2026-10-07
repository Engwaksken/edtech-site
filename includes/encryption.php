<?php
declare(strict_types=1);

function site_encryption_key(): string
{
    $encoded = getenv('APP_ENCRYPTION_KEY') ?: '';
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('APP_ENCRYPTION_KEY must contain a base64-encoded 32-byte key.');
    }
    return $key;
}

function site_encrypt_secret(string $plaintext, string $context): string
{
    if ($plaintext === '') {
        return '';
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', site_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
    if ($ciphertext === false) {
        throw new RuntimeException('Secret encryption failed.');
    }
    return 'enc:v1:' . base64_encode($nonce . $tag . $ciphertext);
}

function site_decrypt_secret(string $stored, string $context): string
{
    if (!str_starts_with($stored, 'enc:v1:')) {
        // Read-only compatibility while the CLI migration encrypts existing records.
        return $stored;
    }
    $payload = base64_decode(substr($stored, 7), true);
    if ($payload === false || strlen($payload) < 28) {
        throw new RuntimeException('Encrypted secret is malformed.');
    }
    $plaintext = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', site_encryption_key(), OPENSSL_RAW_DATA,
        substr($payload, 0, 12), substr($payload, 12, 16), $context);
    if ($plaintext === false) {
        throw new RuntimeException('Encrypted secret authentication failed.');
    }
    return $plaintext;
}
