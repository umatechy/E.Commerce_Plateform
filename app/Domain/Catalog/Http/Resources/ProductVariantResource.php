<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

final class ProductVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCost = $request->user()
            && Gate::forUser($request->user())->allows('viewCostPrice', $this->product);

        return [
            'id' => $this->public_id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'price_minor' => $this->price_minor,
            'sale_price_minor' => $this->sale_price_minor,
            'effective_price_minor' => $this->effectivePriceMinor(),
            'weight' => $this->weight,
            'status' => $this->status,
            'option_values' => $this->option_values,
            $this->mergeWhen($canViewCost, [
                'cost_price_minor' => $this->cost_price_minor,
            ]),
        ];
    }
}
