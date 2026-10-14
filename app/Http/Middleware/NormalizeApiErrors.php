<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B53 (SRS API-010; Master Development Prompt §15; Module 31 §2.23,
 * §40.2, §41, §60) — every error answered under /api has the same shape:
 *
 *     { "code": "validation_failed", "message": "…", "details": {…}, "requestId": "…" }
 *
 * - `code`: a stable, lowercase snake_case code. Codes a controller already
 *   gives (store_closed, platform_role_forbidden …) are kept; otherwise one
 *   is derived from the HTTP status.
 * - `message`: safe for people to read.
 * - `details`: field errors under `fields` for a 422, otherwise what the
 *   endpoint added (or an empty object).
 * - `requestId`: the same value as the X-Request-Id header (API-011).
 *
 * Keys the admin and storefront apps already read (`errors`, `code`,
 * `message`, endpoint extras) stay as they are, so nothing breaks. Debug
 * information (exception, file, line, trace) is never sent outside a local
 * debug run, and a server error never shows its message (§40.3: no stack
 * traces, SQL, paths or secrets).
 */
final class NormalizeApiErrors
{
    private const CODES = [
        400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed',
        409 => 'conflict', 410 => 'gone', 412 => 'precondition_failed', 413 => 'payload_too_large', 415 => 'unsupported_media_type',
        419 => 'session_expired', 422 => 'unprocessable', 423 => 'locked', 429 => 'too_many_requests',
        500 => 'server_error', 502 => 'bad_gateway', 503 => 'service_unavailable', 504 => 'gateway_timeout',
    ];

    private const MESSAGES = [
        400 => 'The request could not be read.', 401 => 'Sign in to continue.', 403 => 'You are not allowed to do this.',
        404 => 'Not found.', 405 => 'This method is not allowed here.', 409 => 'This conflicts with the current state. Reload and try again.',
        419 => 'Your session has expired. Reload the page.', 422 => 'Some of the information is not valid.', 423 => 'This is locked.',
        429 => 'Too many requests. Wait a moment and try again.', 500 => 'Something went wrong on our side. Try again; if it keeps happening, contact support with the request ID.',
        503 => 'The service is temporarily unavailable. Try again shortly.',
    ];

    private const DEBUG_KEYS = ['exception', 'file', 'line', 'trace'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! str_starts_with($request->path(), 'api/') || $response->getStatusCode() < 400 || ! $response instanceof JsonResponse) {
            return $response;
        }
        $body = $response->getData(true);
        if (! is_array($body) || array_is_list($body)) {
            return $response;
        }

        $status = $response->getStatusCode();
        $showDebug = (bool) config('app.debug') && app()->environment('local');
        if (! $showDebug) {
            $body = array_diff_key($body, array_flip(self::DEBUG_KEYS));
        }
        $code = is_string($body['code'] ?? null) && $body['code'] !== ''
            ? $body['code']
            : ($status === 422 && isset($body['errors']) ? 'validation_failed' : (self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'error')));
        $message = $status >= 500 && $status !== 503 && ! $showDebug
            ? self::MESSAGES[500]
            : (is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : (self::MESSAGES[$status] ?? 'The request could not be completed.'));
        $details = $body['details'] ?? null;
        if (! is_array($details)) {
            $details = isset($body['errors']) && is_array($body['errors']) ? ['fields' => $body['errors']] : [];
        }

        $response->setData([
            ...$body,
            'code' => $code,
            'message' => $message,
            'details' => $details === [] ? new \stdClass() : $details,
            'requestId' => RequestId::current(),
        ]);

        return $response;
    }
}
