<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Intigo\ProcessIntigoEvent;
use App\Services\Intigo\IntigoStatusSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/webhooks/intigo — Intigo's parcel.status_changed events.
 * No user auth, no CSRF: the HMAC signature is the authentication.
 *
 *   X-Webhook-Signature = "sha256=" + HMAC-SHA256(raw body, INTIGO_WEBHOOK_SECRET)
 *   body {"event":"parcel.status_changed","event_type":"delivered","timestamp":"…",
 *         "data":{"nid":"…","old_status":4000,"new_status":5000,"status_label":"Livré"}}
 *
 * Answers within milliseconds (Intigo waits 10 s, then retries at +1, +5, +15 min):
 * the event is stored (deduplicated on nid + new_status + timestamp) and
 * processed after the response is sent.
 */
class IntigoWebhookController extends Controller
{
    public function handle(Request $request, IntigoStatusSync $sync): JsonResponse
    {
        $raw = $request->getContent();

        if (!self::validSignature($raw, (string) $request->header('X-Webhook-Signature'), config('intigo.webhook_secret'))) {
            Log::warning('[Intigo webhook] invalid signature', ['ip' => $request->ip()]);
            return response()->json(['success' => false, 'message' => 'Invalid signature.'], 401);
        }

        $body  = json_decode($raw, true);
        $data  = is_array($body) ? ($body['data'] ?? null) : null;
        $event = is_array($body) ? ($body['event'] ?? $request->header('X-Webhook-Event')) : null;

        if (!is_array($data) || empty($data['nid']) || !isset($data['new_status']) || !is_numeric($data['new_status'])) {
            Log::warning('[Intigo webhook] unreadable body', ['body' => mb_substr($raw, 0, 500)]);
            // 2xx: a malformed event will not get better by being retried
            return response()->json(['success' => true, 'ignored' => 'unreadable body']);
        }
        if ($event !== null && $event !== 'parcel.status_changed') {
            return response()->json(['success' => true, 'ignored' => 'event ' . $event]);
        }

        $timestamp = (string) ($body['timestamp'] ?? $request->header('X-Webhook-Timestamp') ?? now()->toIso8601String());
        $eventId = $sync->record(
            (string) $data['nid'],
            (int) $data['new_status'],
            isset($data['old_status']) && is_numeric($data['old_status']) ? (int) $data['old_status'] : null,
            $timestamp,
            isset($data['status_label']) ? (string) $data['status_label'] : null,
            $request->header('X-Intigo-Simulated') === '1' ? 'simulate' : 'webhook',
            isset($body['event_type']) ? (string) $body['event_type'] : null,
            $body,
        );

        if ($eventId !== null) {
            ProcessIntigoEvent::dispatchAfterResponse($eventId);
        }

        return response()->json(['success' => true, 'duplicate' => $eventId === null]);
    }

    /** "sha256=<hex>" against the RAW body, constant-time. */
    public static function validSignature(string $raw, string $header, ?string $secret): bool
    {
        if (!is_string($secret) || $secret === '' || $header === '') {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
        return hash_equals($expected, trim($header));
    }

    public static function sign(string $raw, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $raw, $secret);
    }
}
