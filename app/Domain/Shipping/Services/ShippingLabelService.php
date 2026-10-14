<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Services;

use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Settings\Services\Currencies;
use App\Domain\Shipping\Couriers\CourierParty;
use App\Domain\Shipping\Couriers\CourierRegistry;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Tenancy\Models\Store;
use App\Support\Barcode\Code128;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Phase B49 (Module 13 §55–56): shipping labels.
 *
 * A courier's own label (PDF bytes from its API) is kept as it came. For
 * every other shipment — the store's own rider, "another courier" booked
 * outside, the test courier — the platform prints a 4 × 6 inch label:
 * sender, receiver, tracking number with a Code 128 barcode, the cash to
 * collect (or PREPAID), order, pieces, weight.
 *
 * Labels carry names, phones and addresses (§56): stored on the private
 * disk, served only through the permission-checked, audited label route,
 * never cached by the browser.
 */
final class ShippingLabelService
{
    public const TEMPLATE_VERSION = 1;

    public function __construct(private readonly ConfigService $config) {}

    public static function disk(): string
    {
        return (string) config('returns.photos.disk', 'local'); // the private disk used for return photos
    }

    /** The label's bytes; printed (and kept) on first use. */
    public function pdf(Shipment $shipment): string
    {
        $disk = Storage::disk(self::disk());
        if ($shipment->label_path !== null && $disk->exists($shipment->label_path)) {
            return (string) $disk->get($shipment->label_path);
        }
        $bytes = $this->render($shipment);
        $this->store($shipment, $bytes, 'platform');

        return $bytes;
    }

    /** Keep a label (the courier's, or a fresh platform print after the tracking number changed). */
    public function store(Shipment $shipment, string $bytes, string $source): void
    {
        $path = "shipping-labels/{$shipment->store_id}/{$shipment->public_id}.pdf";
        Storage::disk(self::disk())->put($path, $bytes, ['visibility' => 'private']);
        $shipment->forceFill(['label_path' => $path, 'label_source' => $source])->save();
    }

    /** A platform label must be printed again when what it shows changes (tracking number, courier). */
    public function forget(Shipment $shipment): void
    {
        if ($shipment->label_source === 'platform' && $shipment->label_path !== null) {
            Storage::disk(self::disk())->delete($shipment->label_path);
            $shipment->forceFill(['label_path' => null, 'label_source' => null])->save();
        }
    }

    public function render(Shipment $shipment): string
    {
        $shipment->loadMissing(['order.items', 'items']);
        $order = $shipment->order;
        [$sender, $receiver] = $this->parties($shipment);
        $currency = $shipment->currency;

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('shipping.label', [
            'isReturn' => $shipment->isReturn(),
            'sender' => ['name' => $sender->name, 'phone' => $sender->phone, 'address' => $sender->oneLine()],
            'receiver' => ['name' => $receiver->name, 'phone' => $receiver->phone, 'address' => $receiver->address, 'city' => trim(implode(', ', array_filter([$receiver->city, $receiver->province, $receiver->postalCode])))],
            'carrier' => $this->carrierName($shipment),
            'date' => now()->format('d M Y'),
            'trackingNumber' => $shipment->tracking_number,
            'barcode' => $shipment->tracking_number !== null && preg_match('/^[\x20-\x7E]{1,40}$/', $shipment->tracking_number) === 1 ? base64_encode(Code128::svg($shipment->tracking_number)) : null,
            'codAmount' => $shipment->cod_amount_minor > 0 ? $currency.' '.number_format((float) Currencies::amount($shipment->cod_amount_minor, $currency), Currencies::digits($currency)) : null,
            'orderNumber' => $order->order_number ?? '—',
            'pieces' => $shipment->pieces,
            'weight' => $shipment->weight_grams !== null ? rtrim(rtrim(number_format($shipment->weight_grams / 1000, 2), '0'), '.').' kg' : '—',
            'items' => $shipment->isReturn() ? [] : $shipment->items->map(function ($item) use ($order) {
                $line = $order?->items->firstWhere('id', $item->order_item_id);

                return ($line->product_name_snapshot ?? 'Item').' × '.$item->quantity;
            })->take(6)->all(),
            'reference' => $shipment->public_id,
            'templateVersion' => self::TEMPLATE_VERSION,
        ])->render());
        $dompdf->setPaper([0, 0, 288, 432]); // 4 × 6 inch
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Who sends and who receives. Outbound: the store (its warehouse) to the
     * customer (the order's address snapshot, Module 13 §73). Return: the
     * reverse.
     *
     * @return array{0: CourierParty, 1: CourierParty}
     */
    public function parties(Shipment $shipment): array
    {
        $order = $shipment->order;
        $store = Store::query()->find($shipment->store_id);
        $warehouse = Warehouse::query()->withoutTenantScope()->find($shipment->warehouse_id);
        $storePhone = $this->config->get('store.contact_phone');
        $storeParty = new CourierParty(
            name: (string) ($store->name ?? 'Store'),
            phone: $warehouse?->contact ?: (is_string($storePhone) ? $storePhone : null),
            address: (string) ($warehouse->address ?? ''),
            city: null,
        );
        $customer = CourierParty::fromAddress(
            $order?->shipping_address_snapshot,
            (string) ($order->guest_name ?? $order->customer->name ?? 'Customer'),
            $order->guest_phone ?? $order->customer->phone ?? null,
        );

        return $shipment->isReturn() ? [$customer, $storeParty] : [$storeParty, $customer];
    }

    public function carrierName(Shipment $shipment): string
    {
        return match (true) {
            $shipment->carrier === CourierRegistry::MANUAL => (string) ($shipment->carrier_name ?: 'Courier'),
            $shipment->carrier === 'local_delivery' => 'Store delivery',
            $shipment->carrier === 'store_pickup' => 'Store pickup',
            default => app(CourierRegistry::class)->find($shipment->carrier)?->label() ?? ucwords(str_replace('_', ' ', $shipment->carrier)),
        };
    }
}
