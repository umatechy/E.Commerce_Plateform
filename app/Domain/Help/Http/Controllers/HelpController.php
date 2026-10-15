<?php

declare(strict_types=1);

namespace App\Domain\Help\Http\Controllers;

use App\Domain\Help\Policies\HelpAudiencePolicy;
use App\Domain\Help\Services\HelpLibrary;
use App\Domain\Help\Support\HelpCatalog;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase B54 (Module 34 §11–12, §15, §28, §31, §44) — the help center of
 * the admin, for store staff and Umar Techy staff: browse, search, page
 * help, articles, feedback and release notes ("What's new").
 *
 * What a reader gets is decided here, on the server, by
 * HelpAudiencePolicy. An article the reader may not open answers 404
 * (not 403), so its existence is not revealed either.
 */
final class HelpController
{
    private const HELP_BASE = '/help/articles';

    public function __construct(
        private readonly HelpLibrary $library,
        private readonly HelpAudiencePolicy $audiences,
    ) {}

    public function home(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => [
            ...$this->library->home($this->audiences->audiencesFor($user), 'en', true),
            'unseen_release' => $this->unseenRelease($user),
        ]]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => $this->library->search($data['q'], $this->audiences->audiencesFor($this->user($request)), 'en', 'admin')]);
    }

    public function category(Request $request, string $category): JsonResponse
    {
        abort_unless(isset(HelpCatalog::CATEGORIES[$category]), 404);
        $articles = $this->library->category($category, $this->audiences->audiencesFor($this->user($request)), 'en');
        abort_if($articles === [], 404);

        return response()->json(['data' => ['key' => $category, 'label' => HelpCatalog::categoryLabel($category), 'articles' => $articles]]);
    }

    /** Page help (§12): the articles about the admin page the reader is on. */
    public function page(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:300', 'regex:#^/[A-Za-z0-9/_\-]*$#']]);

        return response()->json(['data' => $this->library->forPage($data['path'], $this->audiences->audiencesFor($this->user($request)))]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $user = $this->user($request);
        $article = $this->library->article($key, $this->audiences->audiencesFor($user), 'en', self::HELP_BASE, $this->storeBase($user));
        abort_if($article === null, 404);

        return response()->json(['data' => $article]);
    }

    public function feedback(Request $request, string $key): JsonResponse
    {
        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
            'reason' => ['nullable', Rule::in(array_keys(HelpCatalog::FEEDBACK_REASONS))],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $this->user($request);
        $stored = $this->library->feedback($key, $this->audiences->audiencesFor($user), 'en', (bool) $data['helpful'], $data['reason'] ?? null, $data['comment'] ?? null, 'admin', $user->isPlatformStaff() ? null : $user->activeStoreId());
        abort_unless($stored, 404);

        return response()->json(['message' => 'Thank you. Your feedback helps us improve this article.'], 201);
    }

    public function releases(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->library->releases(), 'meta' => ['unseen_release' => $this->unseenRelease($this->user($request))]]);
    }

    /** The reader has seen the newest release notes: the "What's new" mark goes away. */
    public function seen(Request $request): JsonResponse
    {
        $latest = $this->library->latestRelease();
        if ($latest !== null) {
            $this->user($request)->forceFill(['help_seen_release' => $latest['version']])->save();
        }

        return response()->json(['data' => ['unseen_release' => null]]);
    }

    private function unseenRelease(User $user): ?string
    {
        $latest = $this->library->latestRelease();

        return $latest !== null && $user->help_seen_release !== $latest['version'] ? $latest['version'] : null;
    }

    /** store: links in customer articles lead to the reader's own storefront. */
    private function storeBase(User $user): ?string
    {
        $storeId = $user->activeStoreId();
        $slug = $storeId === null ? null : Store::query()->withoutGlobalScopes()->whereKey($storeId)->value('slug');

        return $slug === null ? null : "/shop/{$slug}";
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
