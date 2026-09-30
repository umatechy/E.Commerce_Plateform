<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use Illuminate\Support\Facades\DB;

/**
 * Module 32 — proves an audit chain has not been altered since it was
 * written (Phase B22). Detects edited rows (hash mismatch), removed or
 * inserted rows (sequence gap / broken link) and a truncated tail (head
 * mismatch). Tamper-EVIDENT, not tamper-proof: someone with database
 * write access can still rewrite a whole chain and its head, which is
 * why the head hash is also worth exporting off-site.
 */
final class AuditChainVerifier
{
    /**
     * @return array{chain: string, status: string, verified_entries: int, first_broken_sequence: ?int, reason: ?string}
     */
    public function verify(string $chainKey): array
    {
        $head = DB::table('audit_chain_heads')->where('chain_key', $chainKey)->first();

        if ($head === null) {
            return $this->result($chainKey, 'empty', 0);
        }

        $expectedSequence = (int) $head->anchor_sequence + 1;
        $expectedPrevious = $head->anchor_hash;
        $verified = 0;

        foreach (DB::table('audit_logs')->where('chain_key', $chainKey)->orderBy('sequence')->lazy(1000) as $row) {
            $row = (array) $row;
            $sequence = (int) $row['sequence'];

            if ($sequence !== $expectedSequence) {
                return $this->result($chainKey, 'broken', $verified, $expectedSequence, "Entry #{$expectedSequence} is missing.");
            }

            if ($row['previous_hash'] !== $expectedPrevious) {
                return $this->result($chainKey, 'broken', $verified, $sequence, "Entry #{$sequence} does not link to the entry before it.");
            }

            if (! hash_equals((string) $row['hash'], AuditHasher::hash($row))) {
                return $this->result($chainKey, 'broken', $verified, $sequence, "Entry #{$sequence} was modified after it was written.");
            }

            $expectedPrevious = $row['hash'];
            $expectedSequence++;
            $verified++;
        }

        if ((int) $head->last_sequence !== $expectedSequence - 1 || $head->last_hash !== $expectedPrevious) {
            return $this->result($chainKey, 'broken', $verified, $expectedSequence, 'Entries after #'.($expectedSequence - 1).' are missing.');
        }

        return $this->result($chainKey, 'ok', $verified);
    }

    /** @return list<array{chain: string, status: string, verified_entries: int, first_broken_sequence: ?int, reason: ?string}> */
    public function verifyAll(): array
    {
        return DB::table('audit_chain_heads')->orderBy('chain_key')->pluck('chain_key')
            ->map(fn (string $chainKey) => $this->verify($chainKey))
            ->all();
    }

    /** @return array{chain: string, status: string, verified_entries: int, first_broken_sequence: ?int, reason: ?string} */
    private function result(string $chain, string $status, int $verified, ?int $brokenAt = null, ?string $reason = null): array
    {
        return ['chain' => $chain, 'status' => $status, 'verified_entries' => $verified, 'first_broken_sequence' => $brokenAt, 'reason' => $reason];
    }
}
