<?php

declare(strict_types=1);

namespace App\Domain\Tax\Services;

use App\Domain\Orders\Models\Customer;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Phase B46 — gap G3, owner decision 1: the store's tax, server-side only
 * (Module 11 §28, Module 05 §75 "never trust a tax amount from the client").
 *
 * It reads the store's settings (tax.*), finds the rates that apply to each
 * line — by the product's tax class (or the default class), the address the
 * store taxes by (shipping, billing or the store's own country) and the
 * date — and hands the arithmetic to TaxCalculator. Nothing is assumed:
 * with tax off, or no matching rate, the tax is 0. A customer marked tax
 * exempt pays no tax; the order records why.
 *
 * The snapshot it returns is stored on the order as it is, so later changes
 * to rates or settings never rewrite an order (Module 29 §58, §96).
 *
 * @phpstan-import-type Rate from TaxCalculator
 * @phpstan-type TaxLine array{key: string, gross_minor: int, discount_minor: int, tax_class_id: ?int}
 * @phpstan-type Quote array{enabled: bool, prices_include_tax: bool, label: string, lines: array<string, int>, shipping_minor: int, total_minor: int, breakdown: list<array{rate_id: int, name: string, rate_bps: int, base_minor: int, tax_minor: int}>, snapshot: ?array<string, mixed>}
 */
final class TaxService
{
    /** @var array<string, list<Rate>> */
    private array $rateCache = [];

    public function __construct(private readonly ConfigService $config, private readonly TaxCalculator $calculator) {}

    /** @return array{enabled: bool, prices_include_tax: bool, based_on: string, shipping_taxable: bool, discount_basis: string, rounding: string, label: string} */
    public function settings(): array
    {
        return [
            'enabled' => (bool) $this->config->get('tax.enabled'),
            'prices_include_tax' => (bool) $this->config->get('tax.prices_include_tax'),
            'based_on' => (string) $this->config->get('tax.based_on'),
            'shipping_taxable' => (bool) $this->config->get('tax.shipping_taxable'),
            'discount_basis' => (string) $this->config->get('tax.discount_basis'),
            'rounding' => (string) $this->config->get('tax.rounding'),
            'label' => (string) $this->config->get('tax.label'),
        ];
    }

    /**
     * @param list<TaxLine> $lines
     * @param array<string, mixed>|null $shippingAddress
     * @param array<string, mixed>|null $billingAddress
     * @return Quote
     */
    public function quote(array $lines, int $orderDiscountMinor, int $shippingMinor, ?array $shippingAddress, ?array $billingAddress, ?Customer $customer, ?CarbonInterface $on = null): array
    {
        return $this->quoteWith([], $lines, $orderDiscountMinor, $shippingMinor, $shippingAddress, $billingAddress, $customer, $on);
    }

    /**
     * quote() with some settings replaced — only for the admin's test
     * calculation, which must work before tax is turned on.
     *
     * @param array<string, mixed> $overrides
     * @param list<TaxLine> $lines
     * @param array<string, mixed>|null $shippingAddress
     * @param array<string, mixed>|null $billingAddress
     * @return Quote
     */
    public function quoteWith(array $overrides, array $lines, int $orderDiscountMinor, int $shippingMinor, ?array $shippingAddress, ?array $billingAddress, ?Customer $customer, ?CarbonInterface $on = null): array
    {
        $settings = [...$this->settings(), ...$overrides];
        $none = ['lines' => array_fill_keys(array_column($lines, 'key'), 0), 'shipping_minor' => 0, 'total_minor' => 0, 'breakdown' => []];
        if (! $settings['enabled']) {
            return ['enabled' => false, 'prices_include_tax' => false, 'label' => $settings['label'], ...$none, 'snapshot' => null];
        }

        $on ??= now();
        $address = $this->address($settings['based_on'], $shippingAddress, $billingAddress);
        $policy = ['prices_include_tax' => $settings['prices_include_tax'], 'discount_basis' => $settings['discount_basis'], 'rounding' => $settings['rounding']];
        $snapshot = [
            'policy_version' => TaxCalculator::POLICY_VERSION,
            ...$policy,
            'based_on' => $settings['based_on'],
            'shipping_taxable' => $settings['shipping_taxable'],
            'label' => $settings['label'],
            'country' => $address['country'],
            'region' => $address['region'],
            'calculated_at' => $on->toIso8601String(),
        ];

        if ($customer !== null && $customer->tax_exempt) {
            // Module 11 §28 "tax exemptions": the order records the exemption instead of a tax.
            return ['enabled' => true, 'prices_include_tax' => $settings['prices_include_tax'], 'label' => $settings['label'], ...$none,
                'snapshot' => [...$snapshot, 'exempt' => true, 'exemption_reference' => $customer->tax_exemption_reference, 'breakdown' => [], 'shipping_tax_minor' => 0, 'total_minor' => 0]];
        }

        $defaultClass = $this->defaultClassId();
        $calcLines = array_map(fn (array $line) => [
            'key' => $line['key'], 'gross_minor' => $line['gross_minor'], 'discount_minor' => $line['discount_minor'],
            'rates' => $this->ratesFor($line['tax_class_id'] ?? $defaultClass, $address['country'], $address['region'], $on),
        ], $lines);
        $shipping = ['amount_minor' => $shippingMinor, 'rates' => $settings['shipping_taxable'] ? $this->ratesFor($defaultClass, $address['country'], $address['region'], $on) : []];

        $result = $this->calculator->calculate($calcLines, $orderDiscountMinor, $shipping, $policy);

        return ['enabled' => true, 'prices_include_tax' => $settings['prices_include_tax'], 'label' => $settings['label'], ...$result,
            'snapshot' => [...$snapshot, 'exempt' => false, 'breakdown' => $result['breakdown'], 'shipping_tax_minor' => $result['shipping_minor'], 'total_minor' => $result['total_minor']]];
    }

    /**
     * The rates of a class that apply at this address on this day: the
     * active ones for any country or this country, for the whole country or
     * this region.
     *
     * @return list<Rate>
     */
    public function ratesFor(?int $classId, ?string $country, ?string $region, CarbonInterface $on): array
    {
        if ($classId === null) {
            return [];
        }
        $day = $on->toDateString();
        $cacheKey = implode('|', [$classId, $country, Str::lower((string) $region), $day]);

        return $this->rateCache[$cacheKey] ??= TaxRate::query()
            ->where('tax_class_id', $classId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('country')->when($country !== null, fn ($q) => $q->orWhere('country', $country)))
            ->where(fn ($q) => $q->whereNull('region')->when($region !== null && $region !== '', fn ($q) => $q->orWhereRaw('LOWER(region) = ?', [Str::lower(trim((string) $region))])))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $day))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day))
            ->orderBy('id')->get()
            ->map(fn (TaxRate $r) => ['id' => $r->id, 'name' => $r->name, 'rate_bps' => $r->rate_bps])->values()->all();
    }

    public function defaultClassId(): ?int
    {
        $id = TaxClass::query()->where('is_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Where the order is taxed: the address the store chose, else the other
     * one, else the store's own country (a digital order may have no
     * shipping address).
     *
     * @param array<string, mixed>|null $shipping
     * @param array<string, mixed>|null $billing
     * @return array{country: ?string, region: ?string}
     */
    public function address(string $basedOn, ?array $shipping, ?array $billing): array
    {
        $store = ['country' => $this->config->get('store.country'), 'province' => null];
        $order = match ($basedOn) {
            'billing' => [$billing, $shipping, $store],
            'store' => [$store],
            default => [$shipping, $billing, $store],
        };
        foreach ($order as $candidate) {
            $country = is_array($candidate) ? strtoupper(trim((string) ($candidate['country'] ?? ''))) : '';
            if (preg_match('/^[A-Z]{2}$/', $country) === 1) {
                $region = trim((string) ($candidate['province'] ?? $candidate['region'] ?? $candidate['state'] ?? ''));

                return ['country' => $country, 'region' => $region === '' ? null : $region];
            }
        }

        return ['country' => null, 'region' => null];
    }
}
