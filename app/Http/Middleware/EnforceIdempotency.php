<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\DeveloperPlatform\Support\ApiKeyContext;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase B53 (SRS API-007; Module 31 §42; Master Development Prompt §16) —
 * the `Idempotency-Key` header on retry-sensitive writes (orders, refunds,
 * payments, shipments, store credit, invoice payments, credit notes).
 *
 * - Same caller, same call, same key and the same request within 24 hours:
 *   the first answer is replayed (`Idempotent-Replayed: true`) and nothing
 *   runs again.
 * - Same key with a different request: 422 `idempotency_key_reused` (§42.4).
 * - The first request still running: 409 `idempotency_in_progress`.
 * - A server error (5xx) is not kept, so a retry runs for real.
 *
 * The header is optional: without it the endpoint behaves as before (the
 * endpoints that already take an `idempotency_key` field keep it). Keys
 * are scoped to the signed-in person or API key, never shared.
 */
final class EnforceIdempotency
{
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        if (preg_match('/^[A-Za-z0-9._:\-]{8,100}$/', $key) !== 1) {
            return response()->json(['code' => 'idempotency_key_invalid', 'message' => 'Idempotency-Key must be 8 to 100 letters, digits, dots, dashes, underscores or colons.'], 400);
        }
        $actor = $this->actor($request);
        if ($actor === null) {
            return $next($request);
        }
        $call = mb_substr($request->method().' /'.$request->path(), 0, 255);
        $fingerprint = hash('sha256', $call."\n".json_encode($this->canonical($request->all()), JSON_UNESCAPED_UNICODE));
        $context = app(TenantContext::class);
        $storeId = $context->hasStore() ? $context->storeId() : null;
        $rows = fn () => DB::table('idempotency_keys')->where('actor', $actor)->where('request', $call)->where('idempotency_key', $key);

        $rows()->where('expires_at', '<=', now())->delete();
        $existing = $rows()->first();
        if ($existing !== null) {
            return $this->answerFor($existing, $fingerprint);
        }
        try {
            $id = DB::table('idempotency_keys')->insertGetId([
                'store_id' => $storeId, 'actor' => $actor, 'request' => $call, 'idempotency_key' => $key,
                'fingerprint' => $fingerprint, 'created_at' => now(), 'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->inProgress();
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_keys')->where('id', $id)->delete();

            throw $e;
        }
        if ($response->getStatusCode() >= 500 || ! $response instanceof JsonResponse) {
            DB::table('idempotency_keys')->where('id', $id)->delete(); // not kept: a retry runs for real
        } else {
            DB::table('idempotency_keys')->where('id', $id)->update(['status_code' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        }

        return $response;
    }

    private function answerFor(object $row, string $fingerprint): Response
    {
        if (! hash_equals((string) $row->fingerprint, $fingerprint)) {
            return response()->json(['code' => 'idempotency_key_reused', 'message' => 'This Idempotency-Key was already used for a different request. Use a new key for a new request.'], 422);
        }
        if ($row->status_code === null) {
            return $this->inProgress();
        }

        return response((string) $row->response_body, (int) $row->status_code, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }

    private function inProgress(): Response
    {
        return response()->json(['code' => 'idempotency_in_progress', 'message' => 'The first request with this Idempotency-Key is still running. Try again in a moment.'], 409)->withHeaders(['Retry-After' => '2']);
    }

    private function actor(Request $request): ?string
    {
        $user = $request->user();
        if ($user instanceof User) {
            return 'user:'.$user->id;
        }
        if ($user instanceof Customer) {
            return 'customer:'.$user->id;
        }
        $key = app(ApiKeyContext::class);

        return $key->isSet() ? 'apikey:'.$key->get()->id : null;
    }

    /**
     * @param array<mixed> $input
     * @return array<mixed>
     */
    private function canonical(array $input): array
    {
        ksort($input);
        foreach ($input as $k => $v) {
            if (is_array($v)) {
                $input[$k] = $this->canonical($v);
            } elseif (is_object($v)) {
                $input[$k] = get_class($v); // an uploaded file counts by its presence, not its bytes
            }
        }

        return $input;
    }
}
