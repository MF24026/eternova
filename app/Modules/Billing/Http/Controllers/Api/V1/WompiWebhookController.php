<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Api\V1;

use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Jobs\ProcessWompiWebhookEvent;
use App\Modules\Billing\Models\WebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Wompi webhook events. This endpoint is PUBLIC (server-to-server, no auth/tenant
 * middleware) so its ONLY trust boundary is the HMAC check — never act on an unverified
 * payload.
 *
 * It must answer fast (<2s): verify signature, dedupe by event id, queue the real work, and
 * return 204. All domain processing happens asynchronously in ProcessWompiWebhookEvent.
 *
 * Returns explicit responses rather than abort(): the app's exception handler renders a bare
 * HttpException through its generic Throwable->500 fallback, which would mask our 401/400.
 */
final class WompiWebhookController
{
    public function __invoke(Request $request, PaymentGatewayInterface $gateway): Response|JsonResponse
    {
        $payload = $request->getContent();
        // Wompi SV sends the signature in the `wompi_hash` header: HMAC-SHA256 of the raw body.
        $signature = (string) $request->header('wompi_hash', '');

        // 1. HMAC gate. Reject anything not signed by us before parsing further.
        if ($signature === '' || ! $gateway->verifyWebhookSignature($payload, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return response()->json(['message' => 'Malformed payload.'], 400);
        }

        $eventId = $this->extractEventId($decoded);
        if ($eventId === null) {
            return response()->json(['message' => 'Missing event id.'], 400);
        }

        // 2. Idempotency. The (gateway, event_id) UNIQUE makes a replay collide here, so a
        //    duplicate is acknowledged (204) without being re-processed.
        $log = WebhookLog::firstOrCreate(
            ['gateway' => 'wompi', 'event_id' => $eventId],
            [
                'event_type' => (string) ($decoded['event'] ?? ''),
                'status' => WebhookLog::STATUS_RECEIVED,
                'received_at' => now(),
                'payload' => $decoded,
                'signature' => $signature,
            ],
        );

        if (! $log->wasRecentlyCreated) {
            return response()->noContent();
        }

        // 3. Queue the real work and return fast.
        ProcessWompiWebhookEvent::dispatch($log->id);

        return response()->noContent();
    }

    /**
     * Wompi events carry no top-level id; dedupe on the transaction id when present, with a
     * top-level `id` fallback for forward compatibility / tests. The exact production dedupe
     * key is finalized against the sandbox.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function extractEventId(array $decoded): ?string
    {
        $candidate = $decoded['id']
            ?? ($decoded['data']['transaction']['id'] ?? null);

        return $candidate === null ? null : (string) $candidate;
    }
}
