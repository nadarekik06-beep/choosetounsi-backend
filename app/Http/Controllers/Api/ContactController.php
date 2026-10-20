<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * POST /api/contact — storefront contact form → e-mail to the team inbox
 * (config mail.contact_to), queued on "emails".
 *
 * Anti-spam: rate limit "contact" (RouteServiceProvider), a honeypot field
 * ("website", hidden from people) and a minimum fill time ("elapsed_ms"):
 * a bot gets the same 200 answer, but nothing is sent.
 */
class ContactController extends Controller
{
    private const MIN_FILL_MS = 3000;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'min:2', 'max:100'],
            'email'      => ['required', 'email:filter', 'max:190'],
            'phone'      => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ().-]*$/'],
            'topic'      => ['required', Rule::in(array_keys(ContactMessageMail::TOPICS))],
            'message'    => ['required', 'string', 'min:10', 'max:5000'],
            'website'    => ['nullable', 'string', 'max:200'],   // honeypot
            'elapsed_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $ok = response()->json(['success' => true, 'message' => __('messages.contact.sent')]);

        $bot = filled($data['website'] ?? null)
            || (isset($data['elapsed_ms']) && $data['elapsed_ms'] < self::MIN_FILL_MS);
        if ($bot) {
            Log::info('[Contact] dropped (honeypot / too fast)', ['ip' => $request->ip()]);
            return $ok;
        }

        $to = config('mail.contact_to');
        if (!filled($to)) {
            Log::error('[Contact] no MAIL_CONTACT_TO / MAIL_REPLY_TO_ADDRESS / MAIL_FROM_ADDRESS: message not sent');
            return response()->json(['success' => false, 'message' => __('messages.generic.server_error')], 503);
        }

        Mail::to($to)->queue(new ContactMessageMail([
            'name'    => trim(strip_tags($data['name'])),
            'email'   => $data['email'],
            'phone'   => isset($data['phone']) ? trim($data['phone']) : null,
            'topic'   => $data['topic'],
            'message' => trim($data['message']),
            'locale'  => app()->getLocale(),
            'user_id' => $request->user('sanctum')?->id,
            'ip'      => $request->ip(),
        ]));

        return $ok;
    }
}
