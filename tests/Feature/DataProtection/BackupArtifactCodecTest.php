<?php

declare(strict_types=1);

namespace Tests\Feature\DataProtection;

use App\Domain\DataProtection\Exceptions\BackupIntegrityException;
use App\Domain\DataProtection\Models\Backup;
use App\Domain\DataProtection\Models\BackupStatus;
use App\Domain\DataProtection\Services\BackupArtifactCodec;
use App\Domain\DataProtection\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B30 (gap G4) — the stored artifact: compressed, optionally
 * encrypted, and refused when it was changed or cut off
 * (Module 23 §14–15).
 */
final class BackupArtifactCodecTest extends TestCase
{
    use InteractsWithBackups, RefreshDatabase;

    private const SQL = "-- dump\nINSERT INTO customers VALUES (1, 'jane@example.com');\n-- Dump completed\n";

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBackups();
    }

    protected function tearDown(): void
    {
        array_map(fn (string $file) => @unlink($file), $this->files);
        parent::tearDown();
    }

    private function plainFile(string $content = self::SQL): string
    {
        $this->files[] = $path = (string) tempnam(sys_get_temp_dir(), 'codec_test_');
        file_put_contents($path, $content);

        return $path;
    }

    private function useKey(): string
    {
        $key = base64_encode(random_bytes(32));
        config(['backup.encryption_key' => $key]);

        return $key;
    }

    public function test_without_a_key_the_artifact_is_gzip_and_decodes_to_the_same_dump(): void
    {
        $codec = new BackupArtifactCodec();
        $artifact = $codec->encode($this->plainFile());
        $this->files[] = $artifact['path'];

        $this->assertFalse($artifact['encrypted']);
        $this->assertSame('gzip', $artifact['compression']);
        $this->assertSame(self::SQL, gzdecode((string) file_get_contents($artifact['path'])));

        $this->files[] = $decoded = $codec->decode($artifact['path'], false, 'gzip');
        $this->assertSame(self::SQL, file_get_contents($decoded));
    }

    public function test_with_a_key_the_artifact_is_unreadable_without_it_and_round_trips_with_it(): void
    {
        $key = $this->useKey();
        $codec = new BackupArtifactCodec();
        // Larger than one chunk, so the chunked stream is really exercised.
        $sql = str_repeat("INSERT INTO orders VALUES ('secret-customer-data');\n", 5000);

        $artifact = $codec->encode($this->plainFile($sql));
        $this->files[] = $artifact['path'];
        $stored = (string) file_get_contents($artifact['path']);

        $this->assertTrue($artifact['encrypted']);
        $this->assertStringNotContainsString('secret-customer-data', $stored);
        $this->assertFalse(@gzdecode($stored)); // not merely compressed
        // The key is not in the artifact, in any form.
        $this->assertStringNotContainsString($key, $stored);
        $this->assertStringNotContainsString((string) base64_decode($key), $stored);

        $this->files[] = $decoded = $codec->decode($artifact['path'], true, 'gzip');
        $this->assertSame($sql, file_get_contents($decoded));
    }

    public function test_a_changed_cut_off_or_wrongly_keyed_artifact_is_refused(): void
    {
        $this->useKey();
        $codec = new BackupArtifactCodec();
        $artifact = $codec->encode($this->plainFile(str_repeat('row of data;', 20000)));
        $this->files[] = $artifact['path'];
        $good = (string) file_get_contents($artifact['path']);

        $cases = [
            'one changed byte' => substr_replace($good, $good[200] === 'A' ? 'B' : 'A', 200, 1),
            'cut off' => substr($good, 0, (int) (strlen($good) / 2)),
            'not a backup at all' => 'plain text pretending to be a backup',
        ];

        foreach ($cases as $name => $content) {
            try {
                $codec->assertReadable($this->plainFile($content), true, 'gzip');
                $this->fail("Accepted an artifact that is {$name}.");
            } catch (BackupIntegrityException) {
                $this->addToAssertionCount(1);
            }
        }

        // The right bytes, another key.
        $this->useKey();
        $this->expectException(BackupIntegrityException::class);
        $codec->assertReadable($artifact['path'], true, 'gzip');
    }

    public function test_a_damaged_unencrypted_artifact_is_refused(): void
    {
        $codec = new BackupArtifactCodec();
        $artifact = $codec->encode($this->plainFile(str_repeat('row of data;', 20000)));
        $this->files[] = $artifact['path'];
        $good = (string) file_get_contents($artifact['path']);

        foreach ([substr($good, 0, (int) (strlen($good) / 2)), 'not gzip', ''] as $content) {
            try {
                $codec->assertReadable($this->plainFile($content), false, 'gzip');
                $this->fail('Accepted a damaged artifact.');
            } catch (BackupIntegrityException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_encrypted_backup_cannot_be_read_once_the_key_is_gone(): void
    {
        $this->useKey();
        $codec = new BackupArtifactCodec();
        $artifact = $codec->encode($this->plainFile());
        $this->files[] = $artifact['path'];

        config(['backup.encryption_key' => null]);

        $this->expectExceptionMessage('no backup encryption key is configured');
        $codec->decode($artifact['path'], true, 'gzip');
    }

    public function test_a_malformed_key_stops_the_backup_instead_of_weakening_it(): void
    {
        config(['backup.encryption_key' => 'too-short']);

        $this->expectException(BackupIntegrityException::class);
        (new BackupArtifactCodec())->encode($this->plainFile());
    }

    public function test_a_backup_made_with_a_key_is_recorded_as_encrypted_and_still_verifies(): void
    {
        $key = $this->useKey();

        $backup = app(BackupService::class)->requestScheduled(now())['backup']->fresh();

        $this->assertSame(BackupStatus::Verified, $backup->status);
        $this->assertTrue($backup->is_encrypted);
        $this->assertStringEndsWith('.sql.gz.enc', $backup->storage_path);
        $this->assertTrue($backup->manifest['encrypted']);
        $this->assertStringNotContainsString('fake sql dump', Storage::disk('local')->get($backup->storage_path));
        // The key is nowhere in what is recorded about the backup.
        $this->assertStringNotContainsString($key, json_encode(Backup::query()->sole()->getAttributes()));
    }

    public function test_a_backup_from_before_compression_still_decodes(): void
    {
        $this->files[] = $decoded = (new BackupArtifactCodec())->decode($this->plainFile(), false, null);

        $this->assertSame(self::SQL, file_get_contents($decoded));
    }
}
