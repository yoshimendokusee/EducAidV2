<?php

namespace App\Services;

/**
 * MediaEncryptionService
 * Provides authenticated encryption (AES-256-GCM) for binary media at rest with
 * key rotation readiness, header authentication (AAD) and backward compatibility
 * for legacy Version 1 format.
 *
 * Version 1 Format (legacy):
 *  MAGIC(4) | VER(1=0x01) | MIMELEN(1) | MIME | IVLEN(1) | IV | TAGLEN(1) | TAG | CIPHERTEXT
 *  - No key id, no flags, header not included in AAD.
 *
 * Version 2 Format (current):
 *  MAGIC(4) | VER(1=0x02) | KEY_ID(1) | FLAGS(1) | MIMELEN(1) | MIME | IVLEN(1) | IV | TAGLEN(1) | TAG | CIPHERTEXT
 *  - FLAGS bit 0 (0x01) => header authenticated (AAD)
 *  - AAD bytes = everything from MAGIC through IV (inclusive) except TAGLEN/TAG/CIPHERTEXT.
 *
 * Configuration:
 *  Single key mode: MEDIA_ENCRYPTION_KEY=base64(32 bytes)
 *  Multi key mode:  MEDIA_ENCRYPTION_KEYS="1:base64keyA,2:base64keyB"
 *    - Highest key id (or MEDIA_ENCRYPTION_ACTIVE_KEY override) used for new encryptions.
 */
class MediaEncryptionService
{
    private const MAGIC = 'MED1';
    private const VER_V1 = 1;
    private const VER_V2 = 2;
    private const CIPHER = 'aes-256-gcm';
    private const FLAG_AAD = 0x01;

    /** @var array<int,string> key_id => raw 32-byte key */
    private array $keys = [];
    private ?int $activeKeyId = null;

    public function __construct()
    {
        $this->loadKeys();
    }

    /**
     * Load encryption keys from environment configuration
     */
    private function loadKeys(): void
    {
        $multi = env('MEDIA_ENCRYPTION_KEYS');
        if ($multi) {
            $parts = array_filter(array_map('trim', explode(',', $multi)));
            foreach ($parts as $p) {
                if (strpos($p, ':') === false) {
                    continue;
                }
                [$idStr, $b64] = explode(':', $p, 2);
                $id = (int)trim($idStr);
                if ($id < 1 || $id > 255) {
                    continue;
                }
                $raw = base64_decode(trim($b64), true);
                if ($raw !== false && strlen($raw) === 32) {
                    $this->keys[$id] = $raw;
                }
            }
            if (!empty($this->keys)) {
                ksort($this->keys, SORT_NUMERIC);
                $override = env('MEDIA_ENCRYPTION_ACTIVE_KEY');
                if ($override !== false && ctype_digit($override) && isset($this->keys[(int)$override])) {
                    $this->activeKeyId = (int)$override;
                } else {
                    $ids = array_keys($this->keys);
                    $this->activeKeyId = end($ids); // highest id
                }
                return; // done
            }
        }
        // Fallback single key mode
        $single = env('MEDIA_ENCRYPTION_KEY');
        if ($single) {
            $raw = base64_decode($single, true);
            if ($raw !== false && strlen($raw) === 32) {
                $this->keys[1] = $raw;
                $this->activeKeyId = 1;
            }
        }
    }

    /**
     * Check if encryption is enabled and properly configured
     */
    public function isEnabled(): bool
    {
        return !empty($this->keys) && in_array(self::CIPHER, openssl_get_cipher_methods());
    }

    /**
     * Get the currently active encryption key ID
     */
    public function getActiveKeyId(): ?int
    {
        return $this->activeKeyId;
    }

    /**
     * Encrypt data using Version 2 format
     *
     * @param string $data The data to encrypt
     * @param string $mime The MIME type of the data
     * @return string The encrypted blob with header and metadata
     * @throws \RuntimeException when encryption is not configured
     */
    public function encrypt(string $data, string $mime): string
    {
        if (!$this->isEnabled() || $this->activeKeyId === null) {
            throw new \RuntimeException('Media encryption not configured');
        }
        $key = $this->keys[$this->activeKeyId];
        $iv = random_bytes(12);
        $mimeBytes = substr($mime, 0, 255);
        $mimeLen = strlen($mimeBytes);
        $flags = self::FLAG_AAD; // we always authenticate header

        // Build header up to IV for AAD
        $header = self::MAGIC
                . chr(self::VER_V2)
                . chr($this->activeKeyId)
                . chr($flags)
                . chr($mimeLen)
                . $mimeBytes
                . chr(strlen($iv))
                . $iv;
        $tag = '';
        $ciphertext = openssl_encrypt($data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $header);
        if ($ciphertext === false) {
            throw new \RuntimeException('Media encryption failed');
        }
        $blob = $header
              . chr(strlen($tag))
              . $tag
              . $ciphertext;
        return $blob;
    }

    /**
     * Decrypt data (auto-detect version)
     *
     * @param string $blob The encrypted blob
     * @return array ['mime' => string, 'data' => string, 'encrypted' => bool]
     */
    public function decrypt(string $blob): array
    {
        $len = strlen($blob);
        if ($len < 8 || substr($blob, 0, 4) !== self::MAGIC) {
            return ['mime' => '', 'data' => $blob, 'encrypted' => false]; // plaintext
        }
        $offset = 4;
        $ver = ord($blob[$offset++]);
        if ($ver === self::VER_V1) {
            return $this->decryptV1($blob, $offset);
        } elseif ($ver === self::VER_V2) {
            return $this->decryptV2($blob, $offset);
        }
        return ['mime' => 'application/octet-stream', 'data' => '', 'encrypted' => true];
    }

    /**
     * Decrypt Version 1 format (legacy)
     */
    private function decryptV1(string $blob, int $offset): array
    {
        $len = strlen($blob);
        if ($offset >= $len) {
            return ['mime' => 'application/octet-stream', 'data' => '', 'encrypted' => true];
        }
        $mimeLen = ord($blob[$offset++]);
        if ($offset + $mimeLen > $len) {
            return ['mime' => 'application/octet-stream', 'data' => '', 'encrypted' => true];
        }
        $mime = substr($blob, $offset, $mimeLen);
        $offset += $mimeLen;
        if ($offset >= $len) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $ivLen = ord($blob[$offset++]);
        if ($offset + $ivLen > $len) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $iv = substr($blob, $offset, $ivLen);
        $offset += $ivLen;
        if ($offset >= $len) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $tagLen = ord($blob[$offset++]);
        if ($offset + $tagLen > $len) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $tag = substr($blob, $offset, $tagLen);
        $offset += $tagLen;
        $ciphertext = substr($blob, $offset);
        if (!$this->isEnabled()) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        // Try keys (unknown which one if rotated later)
        foreach ($this->keys as $kid => $k) {
            $pt = openssl_decrypt($ciphertext, self::CIPHER, $k, OPENSSL_RAW_DATA, $iv, $tag, '');
            if ($pt !== false) {
                return ['mime' => $mime, 'data' => $pt, 'encrypted' => true];
            }
        }
        return ['mime' => $mime, 'data' => '', 'encrypted' => true];
    }

    /**
     * Decrypt Version 2 format (current)
     */
    private function decryptV2(string $blob, int $offset): array
    {
        $len = strlen($blob);
        if ($offset + 4 > $len) {
            return ['mime' => 'application/octet-stream', 'data' => '', 'encrypted' => true];
        }
        $keyId = ord($blob[$offset++]);
        $flags = ord($blob[$offset++]);
        $mimeLen = ord($blob[$offset++]);
        if ($offset + $mimeLen > $len) {
            return ['mime' => 'application/octet-stream', 'data' => '', 'encrypted' => true];
        }
        $mime = substr($blob, $offset, $mimeLen);
        $offset += $mimeLen;
        if ($offset >= $len) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $ivLen = ord($blob[$offset++]);
        if ($offset + $ivLen > $len) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $iv = substr($blob, $offset, $ivLen);
        $offset += $ivLen;
        if ($offset >= $len) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $tagLen = ord($blob[$offset++]);
        if ($offset + $tagLen > $len) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $tag = substr($blob, $offset, $tagLen);
        $offset += $tagLen;
        $ciphertext = substr($blob, $offset);

        if (!$this->isEnabled()) {
            return ['mime' => $mime, 'data' => '', 'encrypted' => true];
        }
        $aad = substr($blob, 0, 4) // magic
             . chr(self::VER_V2)
             . chr($keyId)
             . chr($flags)
             . chr($mimeLen)
             . substr($mime, 0, $mimeLen)
             . chr($ivLen)
             . $iv;

        // Select key: prefer declared id; fallback to trying all
        $tryKeys = [];
        if (isset($this->keys[$keyId])) {
            $tryKeys[$keyId] = $this->keys[$keyId];
        }
        foreach ($this->keys as $id => $k) {
            if (!isset($tryKeys[$id])) {
                $tryKeys[$id] = $k;
            }
        }
        foreach ($tryKeys as $id => $k) {
            $pt = openssl_decrypt($ciphertext, self::CIPHER, $k, OPENSSL_RAW_DATA, $iv, $tag, ($flags & self::FLAG_AAD) ? $aad : '');
            if ($pt !== false) {
                return ['mime' => $mime, 'data' => $pt, 'encrypted' => true];
            }
        }
        return ['mime' => $mime, 'data' => '', 'encrypted' => true];
    }
}
