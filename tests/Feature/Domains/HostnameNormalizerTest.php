<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use App\Domain\Domains\Exceptions\InvalidHostnameException;
use App\Domain\Domains\Services\HostnameNormalizer;
use Tests\TestCase;

/**
 * Phase B14 — Hostname normalization/validation, reserved-label
 * protection (Module 19 §6-9, Non-Negotiable). Pure unit tests — no
 * database required.
 * STATUS: NOT EXECUTED — DEFERRED TO VS CODE RUNTIME VERIFICATION.
 */
final class HostnameNormalizerTest extends TestCase
{
    public function test_hostname_is_lowercased(): void
    {
        $result = (new HostnameNormalizer())->normalize('MyStore.Example.com');

        $this->assertSame('mystore.example.com', $result);
    }

    public function test_trailing_dot_is_stripped(): void
    {
        $result = (new HostnameNormalizer())->normalize('mystore.example.com.');

        $this->assertSame('mystore.example.com', $result);
    }

    public function test_port_is_stripped(): void
    {
        $result = (new HostnameNormalizer())->normalize('mystore.example.com:8080');

        $this->assertSame('mystore.example.com', $result);
    }

    public function test_full_url_is_rejected(): void
    {
        $this->expectException(InvalidHostnameException::class);
        (new HostnameNormalizer())->normalize('https://mystore.example.com/path');
    }

    public function test_ip_address_is_rejected(): void
    {
        $this->expectException(InvalidHostnameException::class);
        (new HostnameNormalizer())->normalize('192.168.1.1');
    }

    public function test_malformed_hostname_is_rejected(): void
    {
        $this->expectException(InvalidHostnameException::class);
        (new HostnameNormalizer())->normalize('not a valid host!!');
    }

    public function test_reserved_label_is_rejected(): void
    {
        config(['domains.reserved_labels' => ['admin', 'api']]);

        $this->expectException(InvalidHostnameException::class);
        (new HostnameNormalizer())->normalize('admin.example.com');
    }

    public function test_valid_hostname_passes(): void
    {
        $result = (new HostnameNormalizer())->normalize('shop.example.com');

        $this->assertSame('shop.example.com', $result);
    }
}
