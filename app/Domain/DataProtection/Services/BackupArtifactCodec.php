<?php

declare(strict_types=1);

namespace App\Domain\DataProtection\Services;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;

/**
 * Turns a plain SQL dump into the artifact that is stored, and back
 * (Module 23 §14 "Encryption", §15 "Backup Integrity").
 *
 * - Always gzip. The gzip trailer carries a CRC, so a truncated or
 *   damaged artifact fails to decode.
 * - Encrypted when config('backup.encryption_key') is set: libsodium's
 *   secretstream (XChaCha20-Poly1305), in chunks, so a dump of any size
 *   is never held in memory. Every chunk is authenticated and the last
 *   one is marked final, so a changed or cut-off file is refused.
 *
 * The key comes only from the environment. It is never written into the
 * artifact, the manifest, a log line or an audit entry.
 */
final class BackupArtifactCodec
{
    public const COMPRESSION = 'gzip';

    private const MAGIC = "UTBK1\n";
    private const CHUNK = 65536;

    public function encrypts(): bool
    {
        return $this->key() !== null;
    }

    /**
     * @return array{path: string, compression: string, encrypted: bool} a new temp file; the caller deletes it
     */
    public function encode(string $plainPath): array
    {
        $compressed = $this->temp('backup_gz_');
        $this->gzip($plainPath, $compressed);

        if (! $this->encrypts()) {
            return ['path' => $compressed, 'compression' => self::COMPRESSION, 'encrypted' => false];
        }

        try {
            $encrypted = $this->temp('backup_enc_');
            $this->encrypt($compressed, $encrypted);
        } finally {
            @unlink($compressed);
        }

        return ['path' => $encrypted, 'compression' => self::COMPRESSION, 'encrypted' => true];
    }

    /**
     * The plain SQL of an artifact, as a new temp file the caller deletes.
     *
     * @throws BackupIntegrityException when the artifact cannot be decrypted or decompressed
     */
    public function decode(string $artifactPath, bool $encrypted, ?string $compression): string
    {
        $stages = [];

        try {
            $current = $artifactPath;

            if ($encrypted) {
                $stages[] = $decrypted = $this->temp('backup_dec_');
                $this->decrypt($current, $decrypted);
                $current = $decrypted;
            }

            if ($compression === self::COMPRESSION) {
                $stages[] = $plain = $this->temp('backup_sql_');
                $this->gunzip($current, $plain);
                $current = $plain;
            }

            if ($current === $artifactPath) {
                // A backup made before artifacts were compressed: plain SQL already.
                $stages[] = $copy = $this->temp('backup_sql_');
                copy($artifactPath, $copy);
                $current = $copy;
            }

            // Everything but the final file is an intermediate.
            foreach (array_slice($stages, 0, -1) as $intermediate) {
                @unlink($intermediate);
            }

            return $current;
        } catch (\Throwable $e) {
            foreach ($stages as $file) {
                @unlink($file);
            }

            throw $e instanceof BackupIntegrityException ? $e : new BackupIntegrityException('The backup artifact could not be read: '.$e->getMessage());
        }
    }

    /**
     * Module 23 §15 "backup-format validation": decodes the whole artifact
     * and checks that something is in it. Leaves nothing behind.
     *
     * @throws BackupIntegrityException
     */
    public function assertReadable(string $artifactPath, bool $encrypted, ?string $compression): void
    {
        $plain = $this->decode($artifactPath, $encrypted, $compression);

        try {
            if (filesize($plain) === 0) {
                throw new BackupIntegrityException('The backup artifact is empty.');
            }
        } finally {
            @unlink($plain);
        }
    }

    private function gzip(string $from, string $to): void
    {
        $in = $this->open($from, 'rb');
        $out = gzopen($to, 'wb6') ?: throw new BackupIntegrityException('Compression could not start.');

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false || ($chunk !== '' && gzwrite($out, $chunk) === false)) {
                    throw new BackupIntegrityException('Compression failed.');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private function gunzip(string $from, string $to): void
    {
        // Incremental inflate, not gzread(): gzread() returns what it could
        // read of a cut-off file without complaint. Here the stream must
        // reach its end marker, and the gzip trailer's CRC must match.
        $in = $this->open($from, 'rb');
        $out = $this->open($to, 'wb');
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        try {
            if ($context === false) {
                throw new BackupIntegrityException('Decompression could not start.');
            }

            while (! feof($in)) {
                $chunk = (string) fread($in, self::CHUNK);
                $plain = $chunk === '' ? '' : @inflate_add($context, $chunk);

                if ($plain === false) {
                    throw new BackupIntegrityException('The backup artifact is damaged (decompression failed).');
                }

                fwrite($out, $plain);
            }

            if (inflate_get_status($context) !== ZLIB_STREAM_END) {
                throw new BackupIntegrityException('The backup artifact is cut off (the compressed stream has no end).');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function encrypt(string $from, string $to): void
    {
        $in = $this->open($from, 'rb');
        $out = $this->open($to, 'wb');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push((string) $this->key());
            fwrite($out, self::MAGIC.$header);

            do {
                $chunk = (string) fread($in, self::CHUNK);
                $final = feof($in);
                fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state, $chunk, '',
                    $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                ));
            } while (! $final);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function decrypt(string $from, string $to): void
    {
        $key = $this->key() ?? throw new BackupIntegrityException('This backup is encrypted and no backup encryption key is configured.');
        $in = $this->open($from, 'rb');
        $out = $this->open($to, 'wb');

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new BackupIntegrityException('The backup artifact is not in the encrypted backup format.');
            }

            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new BackupIntegrityException('The backup artifact is cut off.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $finished = false;

            while (! $finished) {
                $chunk = (string) fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                $result = $chunk === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);

                if ($result === false) {
                    // Wrong key, changed bytes, or the file ended before its final chunk.
                    throw new BackupIntegrityException('The backup artifact could not be decrypted: it was changed, cut off, or encrypted with another key.');
                }

                [$plain, $tag] = $result;
                fwrite($out, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function key(): ?string
    {
        $configured = (string) config('backup.encryption_key');

        if ($configured === '') {
            return null;
        }

        $key = base64_decode($configured, true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            // Never continue with a key that is not what it should be: the
            // backup would be recorded as encrypted with something unknown.
            throw new BackupIntegrityException('BACKUP_ENCRYPTION_KEY is not valid: it must be base64 of exactly 32 bytes.');
        }

        return $key;
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        return fopen($path, $mode) ?: throw new BackupIntegrityException('A backup working file could not be opened.');
    }

    private function temp(string $prefix): string
    {
        return tempnam(sys_get_temp_dir(), $prefix) ?: throw new BackupIntegrityException('A backup working file could not be created.');
    }
}
