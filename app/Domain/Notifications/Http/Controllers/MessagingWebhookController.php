<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Http\Controllers;

use App\Domain\Notifications\Services\MessagingWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase B50 (Module 21 §35–36): SMS and WhatsApp providers' webhooks. No
 * login — trust comes from the account named by the unguessable token in
 * the address and the provider's signature over the body with that
 * account's own secret (MessagingWebhookService). Meta's subscription check
 * (GET) is answered with its challenge only for the account's verify token.
 */
final class MessagingWebhookController
{
    public function __invoke(Request $request, string $token, MessagingWebhookService $webhooks): Response|JsonResponse
    {
        $account = $webhooks->account($token) ?? abort(404);

        if ($request->isMethod('GET')) {
            $challenge = $webhooks->challenge($account, $request);

            return $challenge === null ? response('Forbidden', 403) : response($challenge, 200, ['Content-Type' => 'text/plain']);
        }

        $result = $webhooks->handle($account, $request);
        if ($result === null) {
            return response()->json(['message' => 'Signature verification failed.'], 403);
        }

        return response()->json(['received' => true, ...$result]);
    }
}
