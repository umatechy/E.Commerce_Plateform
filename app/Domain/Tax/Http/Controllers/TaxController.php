<?php

declare(strict_types=1);

namespace App\Domain\Tax\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Settings\Exceptions\InvalidSettingValueException;
use App\Domain\Settings\Models\SettingScope;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Services\TaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase B46 — gap G3, owner decision 1; Module 33 §29 "Tax settings":
 * the store's tax set-up — how tax is charged (settings, with history),
 * its tax classes and its rates by country and region, and a test
 * calculation. Nothing is pre-filled: the store enters the rates it has
 * confirmed. Every change is audited with what it was before.
 */
final class TaxController
{
    private const SETTINGS = ['enabled', 'prices_include_tax', 'based_on', 'shipping_taxable', 'discount_basis', 'rounding', 'label'];

    public function show(Request $request, TaxService $tax): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', TaxClass::class);

        return response()->json(['data' => [
            'settings' => $tax->settings(),
            'classes' => TaxClass::query()->withCount('rates')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (TaxClass $c) => [...$c->only(['id', 'name', 'description', 'is_default']), 'rates_count' => $c->rates_count, 'products_count' => DB::table('products')->where('tax_class_id', $c->id)->whereNull('deleted_at')->count()]),
            'rates' => TaxRate::query()->orderBy('country')->orderBy('region')->orderBy('name')->get()->map(fn (TaxRate $r) => $this->rate($r)),
            'store_country' => app(ConfigService::class)->get('store.country'),
        ]]);
    }

    public function updateSettings(Request $request, ConfigService $config, TaxService $tax, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', TaxClass::class);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'], 'prices_include_tax' => ['sometimes', 'boolean'], 'shipping_taxable' => ['sometimes', 'boolean'],
            'based_on' => ['sometimes', 'string'], 'discount_basis' => ['sometimes', 'string'], 'rounding' => ['sometimes', 'string'],
            'label' => ['sometimes', 'string', 'max:40'],
        ]);
        $before = $tax->settings();
        if (($data['enabled'] ?? false) && TaxRate::query()->where('is_active', true)->doesntExist()) {
            throw ValidationException::withMessages(['enabled' => 'Add at least one active tax rate before turning tax on.']);
        }

        DB::transaction(function () use ($data, $config, $request) {
            foreach (array_intersect_key($data, array_flip(self::SETTINGS)) as $key => $value) {
                try {
                    $config->set("tax.{$key}", $value, SettingScope::Store, $request->user()->id, 'Tax settings');
                } catch (InvalidSettingValueException $e) {
                    throw ValidationException::withMessages([$key => $e->getMessage()]);
                }
            }
        });
        $after = $tax->settings();
        $audit->record('tax.settings_updated', ['before' => $before, 'after' => $after], null, null, $request->user());

        return response()->json(['data' => $after]);
    }

    public function storeClass(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', TaxClass::class);

        return response()->json(['data' => $this->saveClass(new TaxClass(), $request, $audit)], 201);
    }

    public function updateClass(Request $request, TaxClass $class, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', $class);

        return response()->json(['data' => $this->saveClass($class, $request, $audit)]);
    }

    /** A class with rates is kept (its rates go first); the default class stays; its products fall back to the default. */
    public function destroyClass(Request $request, TaxClass $class, AuditLogger $audit): Response
    {
        Gate::forUser($request->user())->authorize('manage', $class);
        if ($class->is_default) {
            throw ValidationException::withMessages(['class' => 'Make another class the default before deleting this one.']);
        }
        if ($class->rates()->exists()) {
            throw ValidationException::withMessages(['class' => 'Delete or move this class\'s rates first.']);
        }
        $audit->record('tax.class_deleted', ['name' => $class->name], $class, null, $request->user());
        $class->delete();

        return response()->noContent();
    }

    public function storeRate(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', TaxClass::class);

        return response()->json(['data' => $this->saveRate(new TaxRate(), $request, $audit)], 201);
    }

    public function updateRate(Request $request, TaxRate $rate, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', TaxClass::class);

        return response()->json(['data' => $this->saveRate($rate, $request, $audit)]);
    }

    /** Orders keep the rate they used (their tax snapshot), so a rate can go. */
    public function destroyRate(Request $request, TaxRate $rate, AuditLogger $audit): Response
    {
        Gate::forUser($request->user())->authorize('manage', TaxClass::class);
        $audit->record('tax.rate_deleted', $this->rate($rate), $rate, null, $request->user());
        $rate->delete();

        return response()->noContent();
    }

    /**
     * Module 11 §28 "tax exemptions": a customer the store does not charge
     * tax (with the certificate or registration number it relies on).
     * Needs tax.manage as well as customer access; orders placed from now
     * on record the exemption, earlier orders keep their tax.
     */
    public function updateExemption(Request $request, \App\Domain\Orders\Models\Customer $customer, AuditLogger $audit): JsonResponse
    {
        Gate::forUser($request->user())->authorize('manage', TaxClass::class);
        abort_unless(app(\App\Domain\Customers\Policies\CustomerPolicy::class)->manage($request->user()), 403);
        $data = $request->validate([
            'tax_exempt' => ['required', 'boolean'],
            'tax_exemption_reference' => ['nullable', 'required_if:tax_exempt,true', 'string', 'max:80'],
        ]);
        $before = ['tax_exempt' => (bool) $customer->tax_exempt, 'tax_exemption_reference' => $customer->tax_exemption_reference];
        $customer->forceFill([
            'tax_exempt' => $data['tax_exempt'],
            'tax_exemption_reference' => $data['tax_exempt'] ? trim((string) $data['tax_exemption_reference']) : null,
        ])->save();
        $audit->record('tax.customer_exemption_updated', ['before' => $before, 'after' => $customer->only(['tax_exempt', 'tax_exemption_reference'])], $customer, null, $request->user());

        return response()->json(['data' => $customer->only(['tax_exempt', 'tax_exemption_reference'])]);
    }

    /** Try the set-up: the tax on one amount for a class and an address, today, with the current settings. */
    public function preview(Request $request, TaxService $tax): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', TaxClass::class);
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'tax_class_id' => ['nullable', 'integer'],
            'country' => ['required', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:80'],
            'shipping_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
        ]);
        $classId = isset($data['tax_class_id']) ? (TaxClass::query()->whereKey($data['tax_class_id'])->value('id') ?? abort(422)) : null;
        $address = ['country' => strtoupper($data['country']), 'province' => $data['region'] ?? null];
        $settings = $tax->settings();
        // The preview answers even while tax is off, so the set-up can be checked before it goes live.
        $quote = $tax->quoteWith(['enabled' => true], [['key' => 'line0', 'gross_minor' => $data['amount_minor'], 'discount_minor' => 0, 'tax_class_id' => $classId]], 0, (int) ($data['shipping_minor'] ?? 0), $address, $address, null);

        return response()->json(['data' => [
            'tax_minor' => $quote['total_minor'], 'shipping_tax_minor' => $quote['shipping_minor'], 'breakdown' => $quote['breakdown'],
            'prices_include_tax' => $quote['prices_include_tax'], 'enabled' => $settings['enabled'],
            'total_minor' => $data['amount_minor'] + (int) ($data['shipping_minor'] ?? 0) + ($quote['prices_include_tax'] ? 0 : $quote['total_minor']),
        ]]);
    }

    /** @return array<string, mixed> */
    private function saveClass(TaxClass $class, Request $request, AuditLogger $audit): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('tax_classes', 'name')->where('store_id', $class->store_id ?? app(\App\Domain\Tenancy\Support\TenantContext::class)->storeId())->ignore($class->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
        $before = $class->exists ? $class->only(['name', 'description', 'is_default']) : null;

        DB::transaction(function () use ($class, $data) {
            // The first class is the default; there is always exactly one once a class exists.
            $makeDefault = ($data['is_default'] ?? false) || TaxClass::query()->where('is_default', true)->whereKeyNot($class->id ?? 0)->doesntExist();
            if ($makeDefault) {
                TaxClass::query()->where('is_default', true)->whereKeyNot($class->id ?? 0)->update(['is_default' => false]);
            } elseif ($class->is_default && array_key_exists('is_default', $data)) {
                throw ValidationException::withMessages(['is_default' => 'Make another class the default instead.']);
            }
            $class->fill([...$data, 'is_default' => $makeDefault || $class->is_default])->save();
        });
        $audit->record($before === null ? 'tax.class_created' : 'tax.class_updated', ['before' => $before, 'after' => $class->only(['name', 'description', 'is_default'])], $class, null, $request->user());

        return $class->only(['id', 'name', 'description', 'is_default']);
    }

    /** @return array<string, mixed> */
    private function saveRate(TaxRate $rate, Request $request, AuditLogger $audit): array
    {
        $data = $request->validate([
            'tax_class_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'region' => ['nullable', 'string', 'max:80'],
            'rate_bps' => ['required', 'integer', 'min:0', 'max:'.TaxRate::MAX_BPS],
            'is_active' => ['sometimes', 'boolean'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
        if (TaxClass::query()->whereKey($data['tax_class_id'])->doesntExist()) { // tenant-scoped
            throw ValidationException::withMessages(['tax_class_id' => 'Choose one of this store\'s tax classes.']);
        }
        if (($data['region'] ?? null) !== null && ($data['country'] ?? null) === null) {
            throw ValidationException::withMessages(['region' => 'A region needs its country.']);
        }
        $data['country'] = isset($data['country']) ? strtoupper($data['country']) : null;
        $data['region'] = isset($data['region']) && trim($data['region']) !== '' ? trim($data['region']) : null;
        $before = $rate->exists ? $this->rate($rate) : null;
        $rate->fill($data)->save();
        $audit->record($before === null ? 'tax.rate_created' : 'tax.rate_updated', ['before' => $before, 'after' => $this->rate($rate)], $rate, null, $request->user());

        return $this->rate($rate);
    }

    /** @return array<string, mixed> */
    private function rate(TaxRate $r): array
    {
        return [
            'id' => $r->id, 'tax_class_id' => $r->tax_class_id, 'name' => $r->name, 'country' => $r->country, 'region' => $r->region,
            'rate_bps' => $r->rate_bps, 'is_active' => $r->is_active,
            'starts_on' => $r->starts_on?->toDateString(), 'ends_on' => $r->ends_on?->toDateString(),
        ];
    }
}
