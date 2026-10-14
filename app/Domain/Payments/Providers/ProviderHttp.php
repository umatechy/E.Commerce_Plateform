<?php

declare(strict_types=1);

namespace App\Domain\Payments\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The one way adapters call a provider: HTTPS, JSON, bounded time.
 *
 * What a failed call means is decided here, once (Module 12 §60–62):
 * - no answer, a timeout, a server error or an unreadable body → Unknown
 *   (the provider may have taken the money; reconciliation asks again);
 * - 401/403 → Failed, authentication (nothing was charged);
 * - any other 4xx → Failed, invalid request.
 *
 * Request and response bodies are never logged: they carry credentials and
 * wallet numbers (Module 12 §58).
 */
final class ProviderHttp
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>|ProviderResult the decoded answer, or why there is none
     */
    public static function postJson(string $url, array $body, array $headers = [], int $timeoutSeconds = 30): array|ProviderResult
    {
        if (! str_starts_with($url, 'https://')) {
            // Module 12 §72: secure transport only.
            return new ProviderResult(ProviderOutcome::Failed, FailureCategory::InvalidRequest, null, 'Provider address is not HTTPS.');
        }

        try {
            $response = Http::acceptJson()->asJson()->withHeaders($headers)
                ->connectTimeout(10)->timeout($timeoutSeconds)
                ->post($url, $body);
        } catch (ConnectionException) {
            return ProviderResult::unknown(FailureCategory::ProviderUnavailable, 'No answer from the provider.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            return new ProviderResult(ProviderOutcome::Failed, FailureCategory::AuthenticationFailed, (string) $response->status(), 'The provider refused the merchant credentials.');
        }
        if ($response->clientError()) {
            return new ProviderResult(ProviderOutcome::Failed, FailureCategory::InvalidRequest, (string) $response->status(), 'The provider did not accept the request.');
        }
        if (! $response->successful()) {
            return ProviderResult::unknown(FailureCategory::ProviderUnavailable, 'The provider answered with an error ('.$response->status().').');
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : ProviderResult::unknown(FailureCategory::UnknownProviderError, 'The provider answer could not be read.');
    }
}
