<?php

declare(strict_types=1);

use App\Domain\Theme\Support\ThemeOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 14 (2026-10-07): storefronts use sans-serif fonts only.
 * Every stored theme configuration — drafts, live configurations and the
 * publication history (so a rollback never brings a serif font back) —
 * gets the sans-serif replacement of a retired serif font
 * (ThemeOptions::RETIRED_FONTS). Nothing else in the configuration changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite('store_themes', ['draft_config', 'published_config']);
        $this->rewrite('store_theme_publications', ['config']);
    }

    public function down(): void
    {
        // Not reversible on purpose: which store had which serif font is not kept.
    }

    /** @param list<string> $columns */
    private function rewrite(string $table, array $columns): void
    {
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns) {
            foreach ($rows as $row) {
                $changes = [];
                foreach ($columns as $column) {
                    $config = json_decode((string) $row->{$column}, true);
                    if (! is_array($config) || ! is_array($config['tokens'] ?? null)) {
                        continue;
                    }
                    $changed = false;
                    foreach (['font_family', 'heading_font'] as $font) {
                        $name = $config['tokens'][$font] ?? null;
                        if (is_string($name) && isset(ThemeOptions::RETIRED_FONTS[$name])) {
                            $config['tokens'][$font] = ThemeOptions::RETIRED_FONTS[$name];
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        $changes[$column] = json_encode($config);
                    }
                }
                if ($changes !== []) {
                    DB::table($table)->where('id', $row->id)->update($changes);
                }
            }
        });
    }
};
