<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'objective' => ['required', 'in:awareness,new_customer_acquisition,first_purchase,repeat_purchase,abandoned_cart_recovery,product_promotion,category_promotion,seasonal_sale,customer_reactivation'],
            'audience_type' => ['required', 'in:all_customers,segment'],
            'marketing_segment_id' => ['required_if:audience_type,segment', 'nullable', 'integer'],
            'promotion_id' => ['nullable', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
