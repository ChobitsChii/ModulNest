<?php

declare(strict_types=1);

namespace Modulon\Core;

use RuntimeException;

final class SecretBox
{
    /**
     * Verschlüsselt einen Klartext mit AES-256-GCM und liefert ein JSON-Token.
     */
    public static function encrypt(string $plaintext, string $secret): string
    {
        if ($plaintext === '') {
            throw new RuntimeException('Leerer Klartext darf nicht verschlüsselt werden.');
        }
        if ($secret === '') {
            throw new RuntimeException('MAIL_CREDENTIAL_KEY fehlt.');
        }

        $method = 'aes-256-gcm';
        $ivLength = openssl_cipher_iv_length($method);
        if (!is_int($ivLength) || $ivLength <= 0) {
            throw new RuntimeException('Verschlüsselung nicht verfügbar.');
        }

        $iv = random_bytes($ivLength);
        $tag = '';
        $key = hash('sha256', $secret, true);
        $ciphertext = openssl_encrypt(
            $plaintext,
            $method,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if (!is_string($ciphertext) || $ciphertext === '' || $tag === '') {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
        }

        $payload = [
            'v' => 1,
            'alg' => $method,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($ciphertext),
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen.');
        }

        return $json;
    }

    /**
     * Entschlüsselt ein zuvor erzeugtes JSON-Token.
     */
    public static function decrypt(string $token, string $secret): string
    {
        if ($token === '' || $secret === '') {
            throw new RuntimeException('Ungültige Entschlüsselungsparameter.');
        }

        $decoded = json_decode($token, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ungültiges Verschlüsselungsformat.');
        }

        $method = (string) ($decoded['alg'] ?? '');
        if ($method !== 'aes-256-gcm') {
            throw new RuntimeException('Nicht unterstützter Verschlüsselungsalgorithmus.');
        }

        $iv = base64_decode((string) ($decoded['iv'] ?? ''), true);
        $tag = base64_decode((string) ($decoded['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($decoded['data'] ?? ''), true);
        if (!is_string($iv) || !is_string($tag) || !is_string($ciphertext)) {
            throw new RuntimeException('Ungültige Verschlüsselungsdaten.');
        }

        $key = hash('sha256', $secret, true);
        $plaintext = openssl_decrypt(
            $ciphertext,
            $method,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if (!is_string($plaintext) || $plaintext === '') {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen.');
        }

        return $plaintext;
    }
}

