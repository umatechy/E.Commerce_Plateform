<?php

declare(strict_types=1);

namespace App\Domain\Help\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Help\Models\HelpArticle;
use App\Domain\Help\Models\HelpArticleRevision;
use App\Domain\Help\Models\HelpFeedback;
use App\Domain\Help\Models\HelpReleaseNote;
use App\Domain\Help\Services\HelpActionRefused;
use App\Domain\Help\Services\HelpArticleWorkflow;
use App\Domain\Help\Services\HelpLibrary;
use App\Domain\Help\Services\HelpRenderer;
use App\Domain\Help\Support\HelpCatalog;
use App\Domain\Help\Support\HelpContentChecker;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase B54 — Super Admin → Help center (Module 34 §6–10, §28–29, §31,
 * §47.1 "editor access restricted", §56). Every platform role can look;
 * writing, reviewing and publishing need the "help" capability
 * (PlatformRoles::ROUTES).
 *
 * - Articles: every status and language, what waits for review, what is
 *   due for review, built-in articles with a newer repository text, and
 *   feedback counts.
 * - One article: its live text, its open version with the checker's
 *   problems, and its history.
 * - Feedback and searches (what readers could not find).
 * - Release notes: publish a draft, which sets the release date.
 */
final class SuperAdminHelpController
{
    public function __construct(
        private readonly HelpArticleWorkflow $workflow,
        private readonly HelpContentChecker $checker,
        private readonly HelpRenderer $renderer,
        private readonly HelpLibrary $library,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([...HelpCatalog::STATUSES, 'needs_review', 'review_due', 'bundle_update'])],
            'audience' => ['nullable', Rule::in(array_keys(HelpCatalog::AUDIENCES))],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $pending = HelpArticleRevision::query()->where('state', 'in_review')->select('help_article_id');
        $articles = HelpArticle::query()
            ->withCount(['feedback as helpful_count' => fn ($q) => $q->where('helpful', true), 'feedback as not_helpful_count' => fn ($q) => $q->where('helpful', false),
                'feedback as open_feedback_count' => fn ($q) => $q->whereNull('resolved_at')->where(fn ($w) => $w->where('helpful', false)->orWhereNotNull('comment'))])
            ->when($data['status'] ?? null, fn ($q, $status) => match ($status) {
                'needs_review' => $q->whereIn('id', $pending),
                'review_due' => $q->whereIn('status', HelpCatalog::READABLE)->whereDate('review_due_on', '<=', now()),
                'bundle_update' => $q->where('bundle_update_available', true),
                default => $q->where('status', $status),
            })
            ->when($data['audience'] ?? null, fn ($q, $audience) => $q->where('audience', $audience))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('key', 'like', "%{$s}%")))
            ->orderBy('category')->orderBy('title')->orderBy('locale')->get();
        $inReview = HelpArticleRevision::query()->where('state', 'in_review')->pluck('help_article_id')->all();

        return response()->json([
            'data' => $articles->map(fn (HelpArticle $a) => [
                'id' => $a->id, 'key' => $a->key, 'locale' => $a->locale, 'title' => $a->title, 'category' => $a->category,
                'category_label' => HelpCatalog::categoryLabel($a->category), 'audience' => $a->audience, 'type' => $a->type, 'status' => $a->status,
                'source' => $a->source, 'revision' => $a->revision, 'published_at' => $a->published_at?->toIso8601String(),
                'review_due_on' => $a->review_due_on?->toDateString(), 'review_overdue' => $a->review_due_on !== null && $a->review_due_on->isPast() && $a->isReadable(),
                'waiting_for_review' => in_array($a->id, $inReview, true), 'bundle_update_available' => $a->bundle_update_available,
                'helpful' => $a->helpful_count, 'not_helpful' => $a->not_helpful_count, 'open_feedback' => $a->open_feedback_count,
            ])->all(),
            'meta' => $this->catalog(),
        ]);
    }

    public function show(HelpArticle $article): JsonResponse
    {
        $revisions = HelpArticleRevision::query()->where('help_article_id', $article->id)->with(['author:id,name', 'reviewer:id,name'])->orderByDesc('revision')->get();
        $open = $revisions->first(fn (HelpArticleRevision $r) => in_array($r->state, ['draft', 'in_review', 'rejected'], true));
        $english = $article->locale === 'en' ? null : HelpArticle::query()->where('key', $article->key)->where('locale', 'en')->first();

        return response()->json(['data' => [
            'id' => $article->id, 'key' => $article->key, 'locale' => $article->locale, 'status' => $article->status, 'source' => $article->source,
            'revision' => $article->revision, 'published_at' => $article->published_at?->toIso8601String(), 'reviewed_by' => $article->reviewed_by_label,
            'deprecated_at' => $article->deprecated_at?->toIso8601String(), 'replaced_by' => $article->replaced_by,
            'archived_at' => $article->archived_at?->toIso8601String(), 'bundle_update_available' => $article->bundle_update_available,
            'classification' => HelpCatalog::CLASSIFICATION[$article->audience] ?? null,
            'live' => $article->revision > 0 ? [...$article->content(), 'html' => $this->renderer->html((string) $article->body, '/help/articles', null)] : null,
            'translation' => $english === null ? null : ['english_revision' => $english->revision, 'source_revision' => $article->translation_source_revision,
                'outdated' => ($article->translation_source_revision ?? 0) < $english->revision],
            'translations' => HelpArticle::query()->where('key', $article->key)->where('id', '!=', $article->id)->get(['id', 'locale', 'status'])->all(),
            'open' => $open === null ? null : [
                'revision' => $open->revision, 'state' => $open->state, 'content' => $open->content, 'change_note' => $open->change_note,
                'author' => $open->author?->name, 'author_id' => $open->author_id, 'review_note' => $open->review_note,
                'submitted_at' => $open->submitted_at?->toIso8601String(), 'problems' => $this->problems($article, $open->content),
            ],
            'history' => $revisions->map(fn (HelpArticleRevision $r) => [
                'revision' => $r->revision, 'state' => $r->state, 'change_note' => $r->change_note, 'author' => $r->author?->name,
                'reviewer' => $r->reviewer->name ?? ($r->author_id === null && $r->state !== 'draft' ? 'Repository review' : null),
                'review_note' => $r->review_note, 'created_at' => $r->created_at?->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String(),
            ])->all(),
            'feedback' => HelpFeedback::query()->where('help_article_id', $article->id)->orderByDesc('id')->limit(50)->get()
                ->map(fn (HelpFeedback $f) => $this->feedbackRow($f, $article))->all(),
        ], 'meta' => $this->catalog()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{2,79}$/'],
            'locale' => ['required', Rule::in(array_keys(Locales::SUPPORTED))],
            ...$this->contentRules(),
        ]);

        return $this->attempt(function () use ($request, $data) {
            $content = $this->content($data, $data['locale'] === 'en' ? null : HelpArticle::query()->where('key', $data['key'])->where('locale', 'en')->first());
            $article = $this->workflow->create($this->user($request), $data['key'], $data['locale'], $content, $data['change_note'] ?? null);

            return response()->json(['data' => ['id' => $article->id], 'message' => 'Draft saved. Send it for review when it is ready.'], 201);
        });
    }

    public function saveDraft(Request $request, HelpArticle $article): JsonResponse
    {
        $data = $request->validate($this->contentRules());

        return $this->attempt(function () use ($request, $article, $data) {
            $english = $article->locale === 'en' ? null : HelpArticle::query()->where('key', $article->key)->where('locale', 'en')->first();
            $revision = $this->workflow->saveDraft($article, $this->user($request), $this->content($data, $english), $data['change_note'] ?? null);

            return response()->json(['data' => ['revision' => $revision->revision, 'problems' => $this->problems($article, $revision->content)], 'message' => 'Draft saved.']);
        });
    }

    public function submit(Request $request, HelpArticle $article): JsonResponse
    {
        return $this->attempt(function () use ($request, $article) {
            $this->workflow->submit($article, $this->user($request));

            return response()->json(['message' => 'Sent for review. Someone else with the help role reviews and publishes it.']);
        });
    }

    public function approve(Request $request, HelpArticle $article): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $article, $data) {
            $this->workflow->approve($article, $this->user($request), $data['note'] ?? null);

            return response()->json(['message' => 'Published. Readers see this version now.']);
        });
    }

    public function reject(Request $request, HelpArticle $article): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->attempt(function () use ($request, $article, $data) {
            $this->workflow->reject($article, $this->user($request), $data['note']);

            return response()->json(['message' => 'Sent back to the author with your note.']);
        });
    }

    public function deprecate(Request $request, HelpArticle $article): JsonResponse
    {
        $data = $request->validate(['replaced_by' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $article, $data) {
            $this->workflow->deprecate($article, $this->user($request), $data['replaced_by'] ?? null, $data['note'] ?? null);

            return response()->json(['message' => 'Marked as out of date. Readers are sent to the replacement.']);
        });
    }

    public function archive(Request $request, HelpArticle $article): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(function () use ($request, $article, $data) {
            $this->workflow->archive($article, $this->user($request), $data['note'] ?? null);

            return response()->json(['message' => 'Archived. Readers no longer see it.']);
        });
    }

    public function reinstate(Request $request, HelpArticle $article): JsonResponse
    {
        return $this->attempt(function () use ($request, $article) {
            $this->workflow->reinstate($article, $this->user($request));

            return response()->json(['message' => 'Published again.']);
        });
    }

    public function bundleDraft(Request $request, HelpArticle $article): JsonResponse
    {
        return $this->attempt(function () use ($request, $article) {
            $revision = $this->workflow->draftFromBundle($article, $this->user($request));

            return response()->json(['data' => ['revision' => $revision->revision], 'message' => 'The newer built-in text is now the draft. Compare, adjust and send it for review.']);
        });
    }

    /** The editor's preview: the HTML readers would get, and what the checker finds. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:80'], 'locale' => ['nullable', 'string', 'max:8'], ...$this->contentRules()]);
        $english = ($data['locale'] ?? 'en') === 'en' ? null : HelpArticle::query()->where('key', $data['key'])->where('locale', 'en')->first();

        return $this->attempt(function () use ($data, $english) {
            $content = $this->content($data, $english);
            $article = new HelpArticle(['key' => $data['key'], 'locale' => $data['locale'] ?? 'en', 'audience' => $content['audience']]);

            return response()->json(['data' => ['html' => $this->renderer->html((string) $content['body'], '/help/articles', null), 'problems' => $this->problems($article, $content)]]);
        });
    }

    public function insights(): JsonResponse
    {
        $since = now()->subDays(30);
        $searches = DB::table('help_searches')->where('created_at', '>=', $since);

        return response()->json(['data' => [
            'searches_30d' => (clone $searches)->count(),
            'zero_result_30d' => (clone $searches)->where('results', 0)->count(),
            'top_searches' => (clone $searches)->selectRaw('query, surface, count(*) as times, max(results) as results')->groupBy('query', 'surface')->orderByDesc('times')->limit(20)->get(),
            'not_found' => (clone $searches)->where('results', 0)->selectRaw('query, surface, count(*) as times')->groupBy('query', 'surface')->orderByDesc('times')->limit(20)->get(),
            'feedback_30d' => [
                'helpful' => HelpFeedback::query()->where('created_at', '>=', $since)->where('helpful', true)->count(),
                'not_helpful' => HelpFeedback::query()->where('created_at', '>=', $since)->where('helpful', false)->count(),
            ],
            'open_feedback' => HelpFeedback::query()->with('article:id,key,locale,title')->whereNull('resolved_at')
                ->where(fn ($q) => $q->where('helpful', false)->orWhereNotNull('comment'))->orderByDesc('id')->limit(100)->get()
                ->map(fn (HelpFeedback $f) => $this->feedbackRow($f, $f->article))->all(),
        ]]);
    }

    public function resolveFeedback(Request $request, HelpFeedback $feedback): JsonResponse
    {
        if ($feedback->resolved_at === null) {
            $feedback->update(['resolved_at' => now(), 'resolved_by' => $this->user($request)->id]);
            $this->audit->record('help.feedback.resolved', ['feedback_id' => $feedback->id, 'article_id' => $feedback->help_article_id], platform: true, actor: $this->user($request));
        }

        return response()->json(['message' => 'Marked as handled.']);
    }

    public function releases(): JsonResponse
    {
        return response()->json(['data' => HelpReleaseNote::query()->orderByDesc('id')->get()->map(fn (HelpReleaseNote $n) => [
            ...$this->library->release($n), 'published_at' => $n->published_at?->toIso8601String(),
        ])->all()]);
    }

    /** Publishing a release note sets its release date to today (Module 34 §31): a date is never typed in or guessed. */
    public function publishRelease(Request $request, string $version): JsonResponse
    {
        $note = HelpReleaseNote::query()->where('version', $version)->firstOrFail();
        if ($note->status === 'published') {
            return response()->json(['message' => 'These release notes are already published.', 'code' => 'already_published'], 409);
        }
        $note->update(['status' => 'published', 'released_on' => now()->toDateString(), 'published_at' => now(), 'published_by' => $this->user($request)->id]);
        $this->audit->record('help.release.published', ['version' => $note->version], $note, actor: $this->user($request), platform: true);

        return response()->json(['message' => "Release notes {$note->version} are published. Store teams see them under What's new."]);
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        return [
            'categories' => collect(HelpCatalog::CATEGORIES)->map(fn (array $c, string $key) => ['key' => $key, 'label' => $c[0], 'audiences' => $c[2]])->values(),
            'audiences' => HelpCatalog::AUDIENCES,
            'types' => HelpCatalog::TYPES,
            'statuses' => HelpCatalog::STATUSES,
            'locales' => Locales::describe(array_keys(Locales::SUPPORTED)),
            'feedback_reasons' => HelpCatalog::FEEDBACK_REASONS,
            'troubleshooting_headings' => HelpCatalog::TROUBLESHOOTING_HEADINGS,
            'product_version' => HelpCatalog::productVersion(),
        ];
    }

    /** @return array<string, mixed> */
    private function contentRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'summary' => ['required', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:60000'],
            'category' => ['nullable', Rule::in(array_keys(HelpCatalog::CATEGORIES))],
            'type' => ['nullable', Rule::in(array_keys(HelpCatalog::TYPES))],
            'audience' => ['nullable', Rule::in(array_keys(HelpCatalog::AUDIENCES))],
            'module' => ['nullable', 'string', 'regex:/^(0[1-9]|[12][0-9]|3[0-5])$/'],
            'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:40'],
            'keywords' => ['nullable', 'array', 'max:30'], 'keywords.*' => ['string', 'max:60'],
            'error_codes' => ['nullable', 'array', 'max:20'], 'error_codes.*' => ['string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'contexts' => ['nullable', 'array', 'max:20'], 'contexts.*' => ['string', 'max:120', 'regex:#^/[A-Za-z0-9/_\-*]*$#'],
            'related' => ['nullable', 'array', 'max:10'], 'related.*' => ['string', 'max:80'],
            'applies_from' => ['nullable', 'string', 'regex:/^\d+\.\d+(\.\d+)?$/'],
            'applies_until' => ['nullable', 'string', 'regex:/^\d+\.\d+(\.\d+)?$/'],
            'owner' => ['nullable', 'string', 'max:80'],
            'review_due_on' => ['nullable', 'date_format:Y-m-d'],
            'change_note' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * The content of a version. A translation takes everything but its
     * words from the English article.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function content(array $data, ?HelpArticle $english): array
    {
        if ($english !== null) {
            return [...$english->content(), 'title' => $data['title'], 'summary' => $data['summary'], 'body' => $data['body'], 'keywords' => $data['keywords'] ?? []];
        }
        foreach (['category', 'type', 'audience', 'owner'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                throw new HelpActionRefused('content_invalid', "Choose the article's {$required}.", ["{$required} is required"]);
            }
        }
        $content = [];
        foreach (HelpArticle::CONTENT_FIELDS as $field) {
            $content[$field] = $data[$field] ?? (in_array($field, ['tags', 'keywords', 'error_codes', 'contexts', 'related'], true) ? [] : null);
        }
        $content['applies_from'] ??= HelpCatalog::productVersion();

        return $content;
    }

    /**
     * @param array<string, mixed> $content
     * @return list<string>
     */
    private function problems(HelpArticle $article, array $content): array
    {
        return $this->checker->check([...$content, 'key' => $article->key, 'locale' => $article->locale], function (string $key) use ($article, $content): ?string {
            if ($key === $article->key) {
                return (string) ($content['audience'] ?? $article->audience);
            }

            return HelpArticle::query()->where('key', $key)->where('locale', 'en')->whereIn('status', HelpCatalog::READABLE)->value('audience');
        });
    }

    /** @return array<string, mixed> */
    private function feedbackRow(HelpFeedback $f, ?HelpArticle $article): array
    {
        return [
            'id' => $f->id, 'article' => $article === null ? null : ['id' => $article->id, 'key' => $article->key, 'locale' => $article->locale, 'title' => $article->title],
            'revision' => $f->revision, 'helpful' => $f->helpful, 'reason' => $f->reason, 'reason_label' => $f->reason === null ? null : (HelpCatalog::FEEDBACK_REASONS[$f->reason] ?? $f->reason),
            'comment' => $f->comment, 'surface' => $f->surface, 'created_at' => $f->created_at->toIso8601String(), 'resolved_at' => $f->resolved_at?->toIso8601String(),
        ];
    }

    /** @param \Closure(): JsonResponse $action */
    private function attempt(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (HelpActionRefused $refused) {
            return response()->json(['message' => $refused->getMessage(), 'code' => $refused->errorCode, 'problems' => $refused->problems], 422);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
