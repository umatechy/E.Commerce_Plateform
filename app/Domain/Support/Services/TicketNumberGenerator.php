<?php

declare(strict_types=1);

namespace App\Domain\Support\Services;

use App\Domain\Support\Models\SupportDesk;
use Illuminate\Support\Facades\DB;

/**
 * Sequential ticket numbers: per store on the store desk (S-000001 is
 * each store's first), platform-wide on the platform desk (P-000001).
 * Taken under a row lock inside the creating transaction, so numbers are
 * never reused or skipped.
 */
final class TicketNumberGenerator
{
    public function next(SupportDesk $desk, int $storeId): string
    {
        $key = $desk === SupportDesk::Store ? "store:{$storeId}" : 'platform';

        DB::table('support_ticket_sequences')->insertOrIgnore(['key' => $key, 'next_value' => 1]);
        $value = (int) DB::table('support_ticket_sequences')->where('key', $key)->lockForUpdate()->value('next_value');
        DB::table('support_ticket_sequences')->where('key', $key)->update(['next_value' => $value + 1]);

        return $desk->prefix().str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
