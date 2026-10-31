<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Controllers;

use App\Domain\Tenancy\Models\StoreRequest;
use App\Domain\Tenancy\Services\StoreRequestService;
use App\Domain\Tenancy\Support\BusinessCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase S1 — the public "Ask for your store" form on the home page (the
 * platform is closed to sign-up; owner decision 2026-10-30). Throttled, with
 * a honeypot field people never see.
 */
final class StoreRequestController
{
    public function submit(Request $request, StoreRequestService $requests): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:160'],
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'city' => ['nullable', 'string', 'max:80'],
            'business_category' => ['nullable', Rule::in(BusinessCategories::keys())],
            'package_code' => ['nullable', 'string', 'max:64', Rule::exists('packages', 'code')->where('is_active', true)],
            'domain' => ['nullable', 'string', 'max:190', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/i'],
            'hosting' => ['required', Rule::in(StoreRequest::HOSTING)],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'string', 'max:0'], // honeypot: people never see this field
        ], ['domain.regex' => 'Write the domain like mystore.pk, without https:// or slashes.']);
        $requests->submit($data);

        return response()->json(['message' => 'Thank you. Our team will contact you soon about your store, your domain and your hosting.'], 202);
    }
}
