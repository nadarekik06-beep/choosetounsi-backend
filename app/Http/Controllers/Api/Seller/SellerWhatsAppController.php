<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Services\Orders\WhatsApp\SellerWhatsAppMessages;
use App\Services\Orders\WhatsApp\SellerWhatsAppNotifier;
use App\Support\TunisianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Seller settings → "WhatsApp notifications": the number CHOOSE'Tounsi
 * messages about confirmed orders, and the language of those messages.
 *
 *   GET /api/seller/whatsapp
 *   PUT /api/seller/whatsapp  {whatsapp_number: "22 123 456" | "+216…" | "00216…", language: fr|ar}
 *
 * Stored as "216XXXXXXXX" (no "+"), ready for wa.me links.
 */
class SellerWhatsAppController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsapp_number' => 'required|string|max:30',
            'language'        => ['required', Rule::in(SellerWhatsAppMessages::LANGUAGES)],
        ]);

        $number = TunisianPhone::toInternational($data['whatsapp_number']);
        if ($number === null) {
            return response()->json([
                'success' => false,
                'message' => __('seller.whatsapp.invalid_number'),
                'errors'  => ['whatsapp_number' => [__('seller.whatsapp.invalid_number')]],
            ], 422);
        }

        $seller = $request->user();
        $seller->update(['whatsapp_number' => $number, 'preferred_language' => $data['language']]);

        return response()->json([
            'success' => true,
            'message' => __('seller.whatsapp.saved'),
            'data'    => $this->payload($seller->fresh()),
        ]);
    }

    private function payload($seller): array
    {
        $fallback = SellerWhatsAppNotifier::fallbackPhone($seller);

        return [
            'whatsapp_number' => $seller->whatsapp_number,
            'display_number'  => $seller->whatsapp_number ? TunisianPhone::format(substr($seller->whatsapp_number, 3)) : null,
            'language'        => in_array($seller->preferred_language, SellerWhatsAppMessages::LANGUAGES, true) ? $seller->preferred_language : 'fr',
            // Without a WhatsApp number, messages go to this phone (account, then shop)
            'fallback_phone'  => $fallback ? TunisianPhone::format(substr($fallback, 3)) : null,
            'business_number' => TunisianPhone::format(substr((string) config('seller_whatsapp.business_number'), 3)),
        ];
    }
}
