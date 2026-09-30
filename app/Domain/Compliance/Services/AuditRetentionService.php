<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use Illuminate\Support\Facades\DB;

/**
 * Module 32 retention (ADR-003 §43): removes audit entries older than
 * compliance.audit.retention_days while keeping every chain verifiable —
 * the hash of the last removed entry becomes the chain's anchor, and
 * AuditChainVerifier starts from it.
 */
final class AuditRetentionService
{
    /** @return int number of entries removed */
    public function prune(): int
    {
        $cutoff = now()->subDays((int) config('compliance.audit.retention_days'));
        $removed = 0;

        foreach (DB::table('audit_chain_heads')->pluck('chain_key') as $chainKey) {
            $removed += DB::transaction(function () use ($chainKey, $cutoff) {
                DB::table('audit_chain_heads')->where('chain_key', $chainKey)->lockForUpdate()->first();

                $last = DB::table('audit_logs')
                    ->where('chain_key', $chainKey)
                    ->where('created_at', '<', $cutoff)
                    ->orderByDesc('sequence')
                    ->first(['sequence', 'hash']);

                if ($last === null) {
                    return 0;
                }

                $deleted = DB::table('audit_logs')->where('chain_key', $chainKey)->where('sequence', '<=', $last->sequence)->delete();

                DB::table('audit_chain_heads')->where('chain_key', $chainKey)->update([
                    'anchor_sequence' => $last->sequence,
                    'anchor_hash' => $last->hash,
                    'updated_at' => now(),
                ]);

                return $deleted;
            });
        }

        return $removed;
    }
}
