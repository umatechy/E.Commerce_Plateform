<?php

declare(strict_types=1);

namespace App\Domain\Help\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Help\Models\HelpArticle;
use App\Domain\Help\Models\HelpArticleRevision;
use App\Domain\Help\Models\HelpReleaseNote;
use App\Domain\Help\Support\HelpBundle;
use App\Domain\Help\Support\HelpContentChecker;
use Illuminate\Support\Facades\DB;

/**
 * Phase B54 (Module 34 §58–59) — loads the help content kept in the
 * repository into the help center, after every deployment.
 *
 * - Nothing is loaded when any file has a problem (HelpContentChecker):
 *   the help center keeps the version it had.
 * - Built-in articles were reviewed in the repository (pull request), so
 *   they are published as they are, recorded as "Repository review".
 * - A built-in article someone changed in the Super Admin is not
 *   overwritten: it is marked "newer built-in text available".
 * - A built-in article removed from the repository is archived.
 * - Release notes arrive as drafts; Umar Techy publishes them, which
 *   sets the release date. A published note gets corrections.
 */
final class HelpBundleSync
{
    public const REVIEWER = 'Repository review';

    public function __construct(
        private readonly HelpContentChecker $checker,
        private readonly HelpArticleWorkflow $workflow,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<string> every problem in the bundle; empty when it can be loaded */
    public function problems(HelpBundle $bundle): array
    {
        $problems = $bundle->problems;
        $audienceOf = fn (string $key): ?string => $bundle->article($key)['audience'] ?? null;
        foreach ($bundle->articles as $article) {
            foreach ($this->checker->check($article, $audienceOf) as $problem) {
                $problems[] = "{$article['file']}: {$problem}";
            }
        }
        foreach ($bundle->releases as $release) {
            foreach ($this->checker->checkRelease($release) as $problem) {
                $problems[] = "{$release['file']}: {$problem}";
            }
        }

        return $problems;
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, kept_local: int, archived: int, releases: int, problems: list<string>}
     */
    public function sync(?HelpBundle $bundle = null): array
    {
        $bundle ??= HelpBundle::load();
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept_local' => 0, 'archived' => 0, 'releases' => 0, 'problems' => $this->problems($bundle)];
        if ($result['problems'] !== []) {
            return $result;
        }

        DB::transaction(function () use ($bundle, &$result) {
            // English first (HelpBundle orders them), so translations see the English revision.
            foreach ($bundle->articles as $file) {
                $result[$this->article($file)]++;
            }
            $present = array_map(fn (array $a) => $a['key'].'.'.$a['locale'], $bundle->articles);
            HelpArticle::query()->where('source', 'bundled')->whereIn('status', ['published', 'deprecated'])->get()
                ->reject(fn (HelpArticle $a) => in_array($a->key.'.'.$a->locale, $present, true))
                ->each(function (HelpArticle $a) use (&$result) {
                    $a->update(['status' => 'archived', 'archived_at' => now()]);
                    $result['archived']++;
                });
            foreach ($bundle->releases as $release) {
                $result['releases'] += $this->release($release) ? 1 : 0;
            }
            if ($result['created'] + $result['updated'] + $result['archived'] + $result['releases'] + $result['kept_local'] > 0) {
                $this->audit->record('help.synced', array_diff_key($result, ['problems' => true]), platform: true);
            }
        });

        return $result;
    }

    /** @param array<string, mixed> $file */
    private function article(array $file): string
    {
        $content = HelpBundle::contentOf($file);
        $article = HelpArticle::query()->where('key', $file['key'])->where('locale', $file['locale'])->first();

        if ($article === null) {
            $article = HelpArticle::query()->create([
                ...$content, 'key' => $file['key'], 'locale' => $file['locale'], 'source' => 'bundled', 'status' => 'published', 'revision' => 0,
            ]);
            $this->write($article, $content, $file);

            return 'created';
        }
        if ($article->bundle_hash === $file['hash']) {
            return 'unchanged';
        }
        // Changed here since it was loaded (a staff-approved version, or a version
        // in progress): the repository text does not overwrite that work.
        $changedHere = $article->source !== 'bundled'
            || HelpArticleRevision::query()->where('help_article_id', $article->id)->whereNotNull('author_id')->exists();
        if ($changedHere) {
            if (! $article->bundle_update_available) {
                $article->update(['bundle_update_available' => true]);

                return 'kept_local';
            }

            return 'unchanged';
        }
        $this->write($article, $content, $file);

        return 'updated';
    }

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed> $file
     */
    private function write(HelpArticle $article, array $content, array $file): void
    {
        $revision = ((int) HelpArticleRevision::query()->where('help_article_id', $article->id)->max('revision')) + 1;
        HelpArticleRevision::query()->where('help_article_id', $article->id)->where('state', 'published')->update(['state' => 'superseded']);
        HelpArticleRevision::query()->create([
            'help_article_id' => $article->id, 'revision' => $revision, 'state' => 'published', 'content' => $content,
            'change_note' => "Loaded from resources/help/{$file['file']}", 'decided_at' => now(),
        ]);
        $this->workflow->publish($article, $content, $revision, null, self::REVIEWER);
        $article->update(['bundle_hash' => $file['hash'], 'bundle_update_available' => false]);
    }

    /** @param array<string, mixed> $release */
    private function release(array $release): bool
    {
        $note = HelpReleaseNote::query()->firstWhere('version', $release['version']);
        if ($note?->bundle_hash === $release['hash']) {
            return false;
        }
        $values = ['title' => $release['title'], 'summary' => $release['summary'], 'sections' => $release['sections'], 'bundle_hash' => $release['hash']];
        $note === null
            ? HelpReleaseNote::query()->create([...$values, 'version' => $release['version'], 'status' => 'draft'])
            : $note->update($values);

        return true;
    }
}
