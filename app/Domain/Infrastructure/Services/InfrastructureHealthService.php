<?php

declare(strict_types=1);

namespace App\Domain\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Module 20 Phase 29-30 "Monitoring & Health / Health Checks" — Non-
 * Negotiable: "health endpoints must not expose secrets... do not
 * expose detailed infrastructure credentials or internal paths
 * publicly." This is APPLICATION health (can this Laravel process
 * reach its own dependencies), distinct from INFRASTRUCTURE health
 * (server CPU/disk/network, which requires real host-level
 * instrumentation this sandbox cannot provide — see inspection
 * findings) and TENANT STORE health (a future Module 24 concern, not
 * built here, not duplicated).
 */
final class InfrastructureHealthService
{
    /** @return array{status: string, checks: array<string, array{status: string, detail?: string}>} */
    public function check(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
        ];

        $overall = collect($checks)->every(fn ($c) => $c['status'] === 'ok') ? 'ok' : 'degraded';

        return ['status' => $overall, 'checks' => $checks];
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            // Never include the raw exception message in a PUBLIC
            // response (it can contain a hostname/DSN fragment) — the
            // detailed reason is only surfaced via the Super-Admin-only
            // endpoint, never the public one (see controller).
            return ['status' => 'failed', 'detail' => 'unreachable'];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health_check:'.uniqid();
            Cache::put($key, true, 5);
            $ok = Cache::get($key) === true;
            Cache::forget($key);

            return ['status' => $ok ? 'ok' : 'failed'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'detail' => 'unreachable'];
        }
    }

    private function checkStorage(): array
    {
        try {
            $path = 'health-check/'.uniqid().'.txt';
            Storage::disk('local')->put($path, 'ok');
            $ok = Storage::disk('local')->exists($path);
            Storage::disk('local')->delete($path);

            return ['status' => $ok ? 'ok' : 'failed'];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'detail' => 'unreachable'];
        }
    }
}
