<?php

declare(strict_types=1);

namespace App\Domain\Help\Console;

use App\Domain\Help\Models\HelpFeedback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase B54 (Module 34 §29, §72.10 retention) — search analytics are kept
 * help.search_retention_days, handled feedback help.feedback_retention_days.
 * Feedback not handled yet is kept until someone looks at it.
 */
final class MaintainHelpCommand extends Command
{
    protected $signature = 'help:maintain';

    protected $description = 'Remove old help searches and old handled help feedback.';

    public function handle(): int
    {
        $searches = DB::table('help_searches')->where('created_at', '<', now()->subDays(max(30, (int) config('help.search_retention_days'))))->delete();
        $feedback = HelpFeedback::query()->whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays(max(30, (int) config('help.feedback_retention_days'))))->delete();
        $this->info("Removed {$searches} searches and {$feedback} handled feedback entries.");

        return self::SUCCESS;
    }
}
