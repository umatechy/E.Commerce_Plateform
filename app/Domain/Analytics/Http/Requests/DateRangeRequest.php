<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_filter' => ['sometimes', 'in:today,yesterday,last_7_days,last_30_days,this_week,last_week,this_month,last_month,this_quarter,this_year,custom'],
            'start' => ['required_if:date_filter,custom', 'nullable', 'date'],
            'end' => ['required_if:date_filter,custom', 'nullable', 'date'],
            'compare' => ['sometimes', 'boolean'],
        ];
    }
}
