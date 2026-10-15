<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Http\Controllers;

use App\Domain\DeveloperPlatform\Support\ApiKeyContext;
use App\Domain\Marketplace\Models\AppInstallation;
use App\Domain\Marketplace\Services\AppInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase B56 (Module 27 §34–36, §45; SRS APP-001/002) — what a marketplace
 * app's server calls on the developer API.
 *
 * - POST /oauth/token: the authorization-code exchange. One answer for
 *   every failure (invalid_grant), so a guessed code, a wrong secret and
 *   an expired code look the same.
 * - GET /installation: the app's own installation on the key's store:
 *   what it was granted and the store's settings for it (secret fields
 *   included: they are the store's settings for this app only).
 */
final class AppConnectionController
{
    public function token(Request $request, AppInstaller $installer): JsonResponse
    {
        $data = $request->validate([
            'grant_type' => ['required', 'in:authorization_code'],
            'code' => ['required', 'string', 'max:100'],
            'client_id' => ['required', 'string', 'max:60'],
            'client_secret' => ['required', 'string', 'max:100'],
            'redirect_uri' => ['required', 'string', 'max:255'],
        ]);
        $token = $installer->exchange($data['client_id'], $data['client_secret'], $data['code'], $data['redirect_uri']);
        if ($token === null) {
            return response()->json(['error' => 'invalid_grant', 'message' => 'The code is invalid, expired or used, or the client details do not match.', 'code' => 'invalid_grant'], 400)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return response()->json($token)->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function installation(ApiKeyContext $context): JsonResponse
    {
        $key = $context->get();
        $installation = AppInstallation::query()->withoutTenantScope()->with(['app', 'version'])
            ->where('store_id', $key->store_id)->where('developer_application_id', $key->developer_application_id)->where('status', 'active')->first();
        abort_if($installation === null, 404, 'This key does not belong to an installed app.');

        return response()->json(['data' => [
            'installation' => $installation->public_id,
            'app' => ['slug' => $installation->app->slug, 'name' => $installation->app->name, 'version' => $installation->version->version],
            'store' => ['id' => \App\Domain\Tenancy\Models\Store::query()->withoutGlobalScopes()->whereKey($key->store_id)->value('public_id')],
            'scopes' => (array) $installation->granted_scopes,
            'events' => (array) $installation->granted_events,
            'settings' => (object) (array) $installation->config,
        ]]);
    }
}
