<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request / correlation IDs (SRS API-011; Module 32 §63.3 "preserve
 * affected request IDs"). Every request gets one ID that
 * - is returned in the X-Request-Id response header,
 * - is attached to every log line of the request and to the jobs it
 *   queues (Laravel's Context), and
 * - is stored with every audit entry the request writes.
 *
 * A caller may send its own X-Request-Id to correlate with its systems.
 * It is accepted only in a safe shape; anything else is replaced, so the
 * header can never inject content into logs.
 */
final class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('security.request_id.header', 'X-Request-Id');
        $incoming = (string) $request->headers->get($header, '');

        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $id);
        Context::add(self::ATTRIBUTE, $id);

        $response = $next($request);
        $response->headers->set($header, $id);

        return $response;
    }
}
