<?php

declare(strict_types=1);

namespace App\Domain\Domains\Http\Controllers;

use App\Domain\Domains\Exceptions\InvalidHostnameException;
use App\Domain\Domains\Models\Domain;
use App\Domain\Domains\Models\DomainStatus;
use App\Domain\Domains\Models\DomainType;
use App\Domain\Domains\Services\HostnameNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase S2: asked by the shop's proxy before it requests a certificate for
 * a name it has never served ("on-demand TLS"). Yes (200) only for a
 * customer's domain whose ownership is proven (TXT) and that is not
 * suspended or removed — and for its "www." form. Everything else: 404, so
 * nobody can make the proxy request certificates for names that are not
 * stores' (which would also exhaust the certificate authority's limits).
 *
 * Says nothing but yes or no; whether a domain belongs to some store is
 * visible from its DNS anyway.
 */
final class TlsAllowedController
{
    public function __invoke(Request $request, HostnameNormalizer $normalizer): Response
    {
        $host = strtolower((string) $request->query('domain', ''));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        try {
            $normalized = $normalizer->normalize($host);
        } catch (InvalidHostnameException) {
            return response('', 404);
        }
        $allowed = Domain::query()->withoutTenantScope()->where('normalized_hostname', $normalized)
            ->where('domain_type', DomainType::CustomDomain->value)
            ->whereIn('status', [DomainStatus::Verified->value, DomainStatus::Active->value])
            ->exists();

        return response('', $allowed ? 200 : 404);
    }
}
