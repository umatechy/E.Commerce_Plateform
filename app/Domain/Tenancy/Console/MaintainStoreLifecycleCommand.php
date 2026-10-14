<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Console;

use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Services\StoreDeletionRunner;
use App\Domain\Tenancy\Services\StoreLifecycleService;
use App\Support\RequestId;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase B51 (Module 03 §35–37; owner decision 5), hourly:
 *
 * 1. Reminds the owner of a closed store once, some days before its data
 *    is archived (stores.closure_reminder_days).
 * 2. Archives closed stores whose retention period has ended. Suspended
 *    stores are archived only by Umar Techy (a suspension may be lifted).
 * 3. Moves approved deletions on by one step (StoreDeletionRunner).
 *
 * Each store is handled on its own; one that fails is reported and the
 * rest go on. Running twice changes nothing (all steps check state first).
 */
final class MaintainStoreLifecycleCommand extends Command
{
    protected $signature = 'stores:lifecycle';

    protected $description = 'Remind owners of closed stores, archive stores past their retention period, run approved store deletions.';

    public function handle(StoreLifecycleService $lifecycle, StoreDeletionRunner $deletions, RecordsOutboxEvents $outbox, ConfigService $config): int
    {
        RequestId::begin('store-lifecycle');
        $reminderDays = (int) $config->get('stores.closure_reminder_days');
        $reminded = 0;
        Store::query()->where('status', StoreStatus::Cancelled->value)->whereNull('retention_reminded_at')
            ->where('retention_ends_at', '>', now())->where('retention_ends_at', '<=', now()->addDays($reminderDays))
            ->orderBy('id')->get()
            ->each(function (Store $store) use ($outbox, &$reminded) {
                DB::transaction(function () use ($store, $outbox) {
                    $store->forceFill(['retention_reminded_at' => now()])->save();
                    $outbox->recordEventFor($store->id, 'store.lifecycle_changed', ['store_id' => $store->id, 'action' => 'retention_reminder'], "store:{$store->id}:retention-reminder:".$store->retention_ends_at?->toDateString());
                });
                $reminded++;
            });

        $archived = 0;
        Store::query()->where('status', StoreStatus::Cancelled->value)->where('retention_ends_at', '<=', now())->orderBy('id')->get()
            ->each(function (Store $store) use ($lifecycle, &$archived) {
                try {
                    $lifecycle->archive($store, null, 'Retention period ended');
                    $archived++;
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("Store {$store->id}: {$e->getMessage()}");
                }
            });

        $moved = $deletions->runDue();
        $this->info("Reminded {$reminded}, archived {$archived}, deletions moved {$moved}.");

        return self::SUCCESS;
    }
}
