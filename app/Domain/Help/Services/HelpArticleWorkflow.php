<?php

declare(strict_types=1);

namespace App\Domain\Help\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Help\Models\HelpArticle;
use App\Domain\Help\Models\HelpArticleRevision;
use App\Domain\Help\Support\HelpBundle;
use App\Domain\Help\Support\HelpCatalog;
use App\Domain\Help\Support\HelpContentChecker;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase B54 (Module 34 §6.2–6.4, §9, §47.1, §72.4–72.7) — writing and
 * publishing articles in the Super Admin:
 *
 *   draft → in review → published → deprecated (→ its replacement) → archived
 *
 * - A published article stays live while its next version is written:
 *   changes are a revision until approved.
 * - Approval needs a second person: the author of a version cannot
 *   approve it (§9 Review → Approved).
 * - Every version passes HelpContentChecker (metadata, links, secrets)
 *   before it can be sent for review and again before it goes live.
 * - Every step is in the platform audit log.
 */
final class HelpArticleWorkflow
{
    public function __construct(
        private readonly HelpContentChecker $checker,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A new article, written in the Super Admin. It is a draft: nobody
     * outside the Super Admin sees it.
     *
     * @param array<string, mixed> $content
     */
    public function create(User $author, string $key, string $locale, array $content, ?string $note): HelpArticle
    {
        $this->refuseIf(HelpArticle::query()->where('key', $key)->where('locale', $locale)->exists(), 'key_taken', 'An article with this key already exists in this language.');
        if ($locale !== 'en') {
            $english = HelpArticle::query()->where('key', $key)->where('locale', 'en')->first();
            $this->refuseIf($english === null, 'translation_without_source', 'Write the English article first; a translation is made from it.');
        }

        return DB::transaction(function () use ($author, $key, $locale, $content, $note) {
            $article = HelpArticle::query()->create([
                ...$this->columns($content), 'key' => $key, 'locale' => $locale, 'source' => 'staff', 'status' => 'draft', 'revision' => 0,
            ]);
            HelpArticleRevision::query()->create([
                'help_article_id' => $article->id, 'revision' => 1, 'state' => 'draft', 'content' => $this->columns($content),
                'change_note' => $note, 'author_id' => $author->id,
            ]);
            $this->audit->record('help.article.created', ['key' => $key, 'locale' => $locale], $article, actor: $author, platform: true);

            return $article;
        });
    }

    /**
     * Save the open draft of an article (or start one from the live text).
     *
     * @param array<string, mixed> $content
     */
    public function saveDraft(HelpArticle $article, User $author, array $content, ?string $note): HelpArticleRevision
    {
        $this->refuseIf($article->status === 'archived', 'archived', 'This article is archived. Publish it again before changing it.');

        return DB::transaction(function () use ($article, $author, $content, $note) {
            $open = $this->open($article);
            $this->refuseIf($open?->state === 'in_review', 'in_review', 'This version is waiting for review. Approve or send it back first.');
            $columns = $this->columns($content);
            if ($open === null) {
                $open = HelpArticleRevision::query()->create([
                    'help_article_id' => $article->id, 'revision' => $this->nextRevision($article), 'state' => 'draft',
                    'content' => $columns, 'change_note' => $note, 'author_id' => $author->id,
                ]);
            } else {
                $open->update(['content' => $columns, 'change_note' => $note, 'author_id' => $author->id, 'state' => 'draft', 'review_note' => null]);
            }
            if ($article->revision === 0) {
                $article->update($columns); // never published: the row shows the draft in the Super Admin only
            }
            $this->audit->record('help.article.draft_saved', ['key' => $article->key, 'locale' => $article->locale, 'revision' => $open->revision], $article, actor: $author, platform: true);

            return $open;
        });
    }

    public function submit(HelpArticle $article, User $user): HelpArticleRevision
    {
        $open = $this->open($article);
        $this->refuseIf($open === null || $open->state === 'in_review', 'nothing_to_submit', 'There is no draft to send for review.');
        $this->assertValid($article, $open->content);

        return DB::transaction(function () use ($article, $open, $user) {
            $open->update(['state' => 'in_review', 'submitted_at' => now()]);
            if ($article->revision === 0) {
                $article->update(['status' => 'in_review']);
            }
            $this->audit->record('help.article.submitted', ['key' => $article->key, 'locale' => $article->locale, 'revision' => $open->revision], $article, actor: $user, platform: true);

            return $open;
        });
    }

    public function approve(HelpArticle $article, User $reviewer, ?string $note): HelpArticle
    {
        $pending = $this->open($article);
        $this->refuseIf($pending === null || $pending->state !== 'in_review', 'not_in_review', 'There is no version waiting for review.');
        $this->refuseIf($pending->author_id === $reviewer->id, 'self_review', 'Someone else must review and publish what you wrote.');
        $this->assertValid($article, $pending->content);

        return DB::transaction(function () use ($article, $pending, $reviewer, $note) {
            HelpArticleRevision::query()->where('help_article_id', $article->id)->where('state', 'published')->update(['state' => 'superseded']);
            $pending->update(['state' => 'published', 'reviewer_id' => $reviewer->id, 'review_note' => $note, 'decided_at' => now()]);
            $this->publish($article, $pending->content, $pending->revision, $reviewer->id, $reviewer->name);
            $this->audit->record('help.article.published', ['key' => $article->key, 'locale' => $article->locale, 'revision' => $pending->revision, 'author_id' => $pending->author_id], $article, actor: $reviewer, platform: true);

            return $article->refresh();
        });
    }

    public function reject(HelpArticle $article, User $reviewer, string $note): HelpArticleRevision
    {
        $pending = $this->open($article);
        $this->refuseIf($pending === null || $pending->state !== 'in_review', 'not_in_review', 'There is no version waiting for review.');

        return DB::transaction(function () use ($article, $pending, $reviewer, $note) {
            $pending->update(['state' => 'rejected', 'reviewer_id' => $reviewer->id, 'review_note' => $note, 'decided_at' => now()]);
            if ($article->revision === 0) {
                $article->update(['status' => 'draft']);
            }
            $this->audit->record('help.article.sent_back', ['key' => $article->key, 'locale' => $article->locale, 'revision' => $pending->revision], $article, actor: $reviewer, platform: true);

            return $pending;
        });
    }

    /** §8.4, §34: a deprecated article names its replacement (or says why there is none). */
    public function deprecate(HelpArticle $article, User $user, ?string $replacedBy, ?string $note): HelpArticle
    {
        $this->refuseIf($article->status !== 'published', 'not_published', 'Only a published article can be marked as out of date.');
        if ($replacedBy !== null) {
            $target = HelpArticle::query()->where('key', $replacedBy)->where('locale', 'en')->whereIn('status', HelpCatalog::READABLE)->first();
            $this->refuseIf($target === null || $target->key === $article->key, 'replacement_missing', 'The replacement must be another published article.');
            $this->refuseIf(! $this->checker->canLink($article->audience, $target->audience), 'replacement_hidden', 'Readers of this article cannot open that replacement.');
        } else {
            $this->refuseIf(trim((string) $note) === '', 'replacement_required', 'Name the article that replaces it, or say why there is none.');
        }
        $article->update(['status' => 'deprecated', 'deprecated_at' => now(), 'replaced_by' => $replacedBy]);
        $this->audit->record('help.article.deprecated', ['key' => $article->key, 'locale' => $article->locale, 'replaced_by' => $replacedBy, 'note' => $note], $article, actor: $user, platform: true);

        return $article;
    }

    public function archive(HelpArticle $article, User $user, ?string $note): HelpArticle
    {
        $this->refuseIf($article->status === 'archived', 'archived', 'This article is already archived.');
        $article->update(['status' => 'archived', 'archived_at' => now()]);
        $this->audit->record('help.article.archived', ['key' => $article->key, 'locale' => $article->locale, 'note' => $note], $article, actor: $user, platform: true);

        return $article;
    }

    /** Put a deprecated or archived article back, with the version that was live. */
    public function reinstate(HelpArticle $article, User $user): HelpArticle
    {
        $this->refuseIf(! in_array($article->status, ['deprecated', 'archived'], true) || $article->revision === 0, 'not_reinstatable', 'Only an article that was published can be put back.');
        $article->update(['status' => 'published', 'deprecated_at' => null, 'archived_at' => null, 'replaced_by' => null]);
        $this->audit->record('help.article.reinstated', ['key' => $article->key, 'locale' => $article->locale], $article, actor: $user, platform: true);

        return $article;
    }

    /**
     * A built-in article was changed here and the repository has a newer
     * text: start a draft from it, to compare and merge.
     */
    public function draftFromBundle(HelpArticle $article, User $user): HelpArticleRevision
    {
        $this->refuseIf($article->source !== 'bundled' || ! $article->bundle_update_available, 'no_bundle_update', 'There is no newer built-in text for this article.');
        $bundled = HelpBundle::load()->article($article->key, $article->locale);
        $this->refuseIf($bundled === null, 'no_bundle_update', 'The built-in text is no longer in this version.');
        $revision = $this->saveDraft($article, $user, HelpBundle::contentOf($bundled), 'Newer built-in text');
        $article->update(['bundle_update_available' => false, 'bundle_hash' => $bundled['hash']]);

        return $revision;
    }

    /**
     * Make $content the live text of $article (also used by the repository sync).
     *
     * @param array<string, mixed> $content
     */
    public function publish(HelpArticle $article, array $content, int $revision, ?int $publishedBy, string $reviewedBy): void
    {
        $english = $article->locale === 'en' ? null : HelpArticle::query()->where('key', $article->key)->where('locale', 'en')->first();
        $article->update([
            ...$this->columns($content),
            'status' => in_array($article->status, ['deprecated', 'archived'], true) ? $article->status : 'published',
            'revision' => $revision,
            'published_at' => now(),
            'published_by' => $publishedBy,
            'reviewed_by_label' => $reviewedBy,
            'translation_source_revision' => $english?->revision,
        ]);
    }

    /** @param array<string, mixed> $content */
    private function assertValid(HelpArticle $article, array $content): void
    {
        $problems = $this->checker->check([...$content, 'key' => $article->key, 'locale' => $article->locale], function (string $key) use ($article): ?string {
            if ($key === $article->key) {
                return $article->audience;
            }

            return HelpArticle::query()->where('key', $key)->where('locale', 'en')->whereIn('status', HelpCatalog::READABLE)->value('audience');
        });
        if ($problems !== []) {
            throw new HelpActionRefused('content_invalid', 'Fix these first: '.implode('; ', $problems).'.', $problems);
        }
    }

    private function open(HelpArticle $article): ?HelpArticleRevision
    {
        return HelpArticleRevision::query()->where('help_article_id', $article->id)->whereIn('state', ['draft', 'in_review', 'rejected'])->orderByDesc('revision')->first();
    }

    private function nextRevision(HelpArticle $article): int
    {
        return ((int) HelpArticleRevision::query()->where('help_article_id', $article->id)->max('revision')) + 1;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function columns(array $content): array
    {
        $columns = [];
        foreach (HelpArticle::CONTENT_FIELDS as $field) {
            $value = $content[$field] ?? null;
            $columns[$field] = in_array($field, ['tags', 'keywords', 'error_codes', 'contexts', 'related'], true)
                ? array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $value), fn ($v) => $v !== ''))
                : ($value === '' ? null : $value);
        }

        return $columns;
    }

    private function refuseIf(bool $condition, string $code, string $message): void
    {
        if ($condition) {
            throw new HelpActionRefused($code, $message);
        }
    }
}
