<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Models\Badge;
use App\Domain\Compliance\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Phase B43 — Module 06 §36: the store's own badges. A label is unique in
 * the store (compared without regard to case); the tone is one of the
 * theme's badge colours; priority orders badges on a card (higher first —
 * automatic ones: out of stock 100, sale 90, low stock 80, new 70,
 * bestseller 60, featured 40).
 */
final class BadgeController
{
    public function index(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Badge::class);
        $counts = DB::table('product_badge')->selectRaw('badge_id, count(*) as c')->groupBy('badge_id')->pluck('c', 'badge_id');

        return response()->json(['data' => Badge::query()->orderByDesc('priority')->orderBy('label')->get()
            ->map(fn (Badge $b) => $this->present($b, (int) ($counts[$b->id] ?? 0)))->values()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', Badge::class);
        $badge = Badge::query()->create($this->validated($request, null));
        $audit->record('badge.created', ['label' => $badge->label], $badge, null, $request->user());

        return response()->json(['data' => $this->present($badge, 0)], 201);
    }

    public function update(Request $request, Badge $badge, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $badge);
        $badge->update($this->validated($request, $badge));
        $audit->record('badge.updated', ['label' => $badge->label], $badge, null, $request->user());

        return response()->json(['data' => $this->present($badge, (int) DB::table('product_badge')->where('badge_id', $badge->id)->count())]);
    }

    public function destroy(Request $request, Badge $badge, AuditLogger $audit): \Illuminate\Http\Response
    {
        Gate::forUser($request->user())->authorize('manage', $badge);
        $badge->delete(); // its product links go with it (cascade)
        $audit->record('badge.deleted', ['label' => $badge->label], $badge, null, $request->user());

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Badge $badge): array
    {
        $data = $request->validate([
            'label' => [$badge === null ? 'required' : 'sometimes', 'string', 'max:40'],
            'tone' => ['sometimes', Rule::in(Badge::TONES)],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['label'])) {
            $data['label'] = trim(preg_replace('/\s+/u', ' ', $data['label']) ?? '');
            $taken = Badge::query()->whereRaw('LOWER(label) = ?', [mb_strtolower($data['label'])])->when($badge, fn ($q) => $q->whereKeyNot($badge->id))->exists();
            if ($data['label'] === '' || $taken) {
                throw \Illuminate\Validation\ValidationException::withMessages(['label' => $data['label'] === '' ? 'Give the badge a label.' : 'A badge with this label already exists.']);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Badge $badge, int $products): array
    {
        return ['id' => $badge->id, 'label' => $badge->label, 'tone' => $badge->tone, 'priority' => $badge->priority, 'is_active' => $badge->is_active, 'product_count' => $products];
    }
}
