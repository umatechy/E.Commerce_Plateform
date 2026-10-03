<?php

declare(strict_types=1);

namespace App\Domain\Customers\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Customers\Exceptions\CustomerActionRefusedException;
use App\Domain\Customers\Models\CustomerGroup;
use App\Domain\Customers\Models\CustomerNote;
use App\Domain\Customers\Models\CustomerTag;
use App\Domain\Customers\Policies\CustomerPolicy;
use App\Domain\Customers\Services\CustomerImport;
use App\Domain\Customers\Services\CustomerManagement;
use App\Domain\Orders\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module 10 §24–25, §32, §47–49 (Phase B32): customer notes, the store's
 * customer groups and tags, and customer import. Tenant-scoped through
 * the models; authorized by CustomerPolicy.
 */
final class CustomerRecordsController
{
    public function __construct(
        private readonly CustomerManagement $customers,
        private readonly AuditLogger $audit,
    ) {}

    // --- Notes (§32) ---

    public function notes(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'view');

        return response()->json(['data' => $customer->notes()->with('author')->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (CustomerNote $n) => $this->note($n, $request))->values()]);
    }

    public function addNote(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize($request, 'manage');
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        try {
            $note = $this->customers->addNote($customer, $data['body'], $request->user());
        } catch (CustomerActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => $this->note($note, $request)], 201);
    }

    public function deleteNote(Request $request, Customer $customer, CustomerNote $note): JsonResponse
    {
        $this->authorize($request, 'manage');
        abort_unless($note->customer_id === $customer->id, 404);

        $this->customers->deleteNote($note, $request->user());

        return response()->json(status: 204);
    }

    // --- Groups (§25) ---

    public function groups(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');

        return response()->json(['data' => CustomerGroup::query()->withCount('customers')->orderBy('name')->get()->map(fn (CustomerGroup $g) => $this->group($g))->values()]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $this->authorize($request, 'manage');
        $this->assertAdvanced();
        $data = $request->validate($this->groupRules());

        $group = CustomerGroup::query()->create($data);
        $this->audit->record('customer_group.created', ['name' => $group->name], $group, actor: $request->user());

        return response()->json(['data' => $this->group($group->loadCount('customers'))], 201);
    }

    public function updateGroup(Request $request, CustomerGroup $group): JsonResponse
    {
        $this->authorize($request, 'manage');
        $this->assertAdvanced();
        $data = $request->validate($this->groupRules($group));

        $before = $group->name;
        $group->update($data);
        $this->audit->record('customer_group.updated', ['from' => $before, 'to' => $group->name], $group, actor: $request->user());

        return response()->json(['data' => $this->group($group->loadCount('customers'))]);
    }

    /** The customers of a deleted group keep everything else; they are simply in no group. */
    public function deleteGroup(Request $request, CustomerGroup $group): JsonResponse
    {
        $this->authorize($request, 'manage');
        $this->assertAdvanced();

        $members = $group->customers()->count();
        $group->delete();
        $this->audit->record('customer_group.deleted', ['name' => $group->name, 'customers' => $members], $group, actor: $request->user());

        return response()->json(status: 204);
    }

    // --- Tags (§24) ---

    public function tags(Request $request): JsonResponse
    {
        $this->authorize($request, 'view');

        return response()->json(['data' => CustomerTag::query()->withCount('customers')->orderBy('name')->get()->map(fn (CustomerTag $t) => $this->tag($t))->values()]);
    }

    public function renameTag(Request $request, CustomerTag $tag): JsonResponse
    {
        $this->authorize($request, 'manage');
        $this->assertAdvanced();
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $normalized = CustomerTag::normalize($data['name']);
        if (CustomerTag::query()->where('normalized_name', $normalized)->whereKeyNot($tag->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'Your store already has this tag.']);
        }

        $before = $tag->name;
        $tag->update(['name' => trim($data['name']), 'normalized_name' => $normalized]);
        $this->audit->record('customer_tag.renamed', ['from' => $before, 'to' => $tag->name], $tag, actor: $request->user());

        return response()->json(['data' => $this->tag($tag->loadCount('customers'))]);
    }

    /** Removes the tag from every customer that has it. */
    public function deleteTag(Request $request, CustomerTag $tag): JsonResponse
    {
        $this->authorize($request, 'manage');
        $this->assertAdvanced();

        $count = $tag->customers()->count();
        $tag->delete();
        $this->audit->record('customer_tag.deleted', ['name' => $tag->name, 'customers' => $count], $tag, actor: $request->user());

        return response()->json(status: 204);
    }

    // --- Import (§47–49) ---

    public function previewImport(Request $request, CustomerImport $import): JsonResponse
    {
        $this->authorize($request, 'import');
        $this->assertAdvanced();
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.CustomerImport::MAX_KILOBYTES]]);

        try {
            return response()->json(['data' => $import->preview($request->file('file'), $request->user())]);
        } catch (CustomerActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    public function confirmImport(Request $request, string $import, CustomerImport $imports): JsonResponse
    {
        $this->authorize($request, 'import');
        $this->assertAdvanced();

        try {
            return response()->json(['data' => $imports->confirm($import, $request->user())]);
        } catch (CustomerActionRefusedException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    /** @return array<string, list<mixed>> */
    private function groupRules(?CustomerGroup $group = null): array
    {
        $storeId = app(\App\Domain\Tenancy\Support\TenantContext::class)->storeId();

        return [
            'name' => [$group === null ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('customer_groups', 'name')->where('store_id', $storeId)->ignore($group?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    private function note(CustomerNote $note, Request $request): array
    {
        return [
            'id' => $note->public_id,
            'body' => $note->body,
            'author' => $note->author?->name,
            'is_yours' => $note->author_user_id === $request->user()->id,
            'created_at' => $note->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function group(CustomerGroup $group): array
    {
        return ['id' => $group->public_id, 'name' => $group->name, 'description' => $group->description, 'customers_count' => (int) ($group->customers_count ?? 0)];
    }

    /** @return array<string, mixed> */
    private function tag(CustomerTag $tag): array
    {
        return ['id' => $tag->public_id, 'name' => $tag->name, 'customers_count' => (int) ($tag->customers_count ?? 0)];
    }

    /** Groups, tags and import: Business and Premium (owner decision 2026-10-03, Module 10 §87). Reading what exists stays open. */
    private function assertAdvanced(): void
    {
        app(\App\Domain\Packages\Services\EntitlementService::class)->assertFeatureEntitled('customers.advanced');
    }
    private function authorize(Request $request, string $ability): void
    {
        abort_unless(app(CustomerPolicy::class)->{$ability}($request->user()), 403);
    }
}
