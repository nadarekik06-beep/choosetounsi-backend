<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\Intigo\BordereauService;
use App\Services\Intigo\IntigoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The seller prints the Intigo bordereau of their own parcels.
 *
 *   GET  /api/seller/orders/{id}/bordereau   one PDF
 *   POST /api/seller/bordereaux/bulk         {ids: [...]} → one merged PDF
 *
 * 403 another seller's order · 409 no Intigo parcel yet · 502 Intigo failed (logged).
 * Never a 500 with details: Intigo's answer stays in the logs.
 */
class SellerBordereauController extends Controller
{
    public function __construct(private BordereauService $bordereaux) {}

    public function show(Request $request, int $id): Response
    {
        $so = SellerOrder::find($id);
        if (!$so) {
            return $this->error(__('seller.bordereau_not_found'), 404);
        }
        if ((int) $so->seller_id !== (int) $request->user()->id) {
            return $this->error(__('seller.bordereau_forbidden'), 403);
        }
        if (!BordereauService::available($so)) {
            return $this->error(__('seller.bordereau_not_ready'), 409, 'no_parcel');
        }

        try {
            $pdf = $this->bordereaux->pdf($so);
        } catch (\Throwable $e) {
            return $this->intigoFailed($so, $e);
        }

        $this->bordereaux->record($so, $request->user(), $request, 'seller');
        return $this->pdfResponse($pdf, $this->bordereaux->filename($so));
    }

    public function bulk(Request $request): Response
    {
        $max  = (int) config('intigo.bordereau.bulk_max', 50);
        $data = $request->validate([
            'ids'   => "required|array|min:1|max:{$max}",
            'ids.*' => 'integer|distinct',
        ]);

        $sellerId = (int) $request->user()->id;
        $ids      = array_map('intval', $data['ids']);
        // Only the seller's own parcels: other ids are skipped, never revealed
        $orders = SellerOrder::whereIn('id', $ids)->where('seller_id', $sellerId)->get()->keyBy('id');

        $pdfs = $printed = $skipped = [];
        foreach ($ids as $id) {
            $so = $orders->get($id);
            if (!$so || !BordereauService::available($so)) {
                $skipped[] = $id;
                continue;
            }
            try {
                $pdfs[]    = $this->bordereaux->pdf($so);
                $printed[] = $so;
            } catch (\Throwable $e) {
                Log::warning("[Bordereau] bulk: seller_order {$so->id} skipped: " . $e->getMessage());
                $skipped[] = $id;
            }
        }

        if (!$pdfs) {
            return $this->error(__('seller.bordereau_bulk_none'), 409, 'no_parcel', ['skipped' => $skipped]);
        }

        try {
            $merged = count($pdfs) === 1 ? $pdfs[0] : $this->bordereaux->merge($pdfs);
        } catch (\Throwable $e) {
            Log::error('[Bordereau] bulk merge failed: ' . $e->getMessage());
            return $this->error(__('seller.bordereau_merge_failed'), 422, 'merge_failed');
        }

        foreach ($printed as $so) {
            $this->bordereaux->record($so, $request->user(), $request, 'seller');
        }

        return $this->pdfResponse($merged, 'bordereaux-' . now()->format('Ymd-Hi') . '.pdf', [
            'X-Bordereaux-Printed' => implode(',', array_map(fn ($so) => $so->id, $printed)),
            'X-Bordereaux-Skipped' => implode(',', $skipped),
        ]);
    }

    // ── Shared with the admin controller ──────────────────────────────────

    public static function pdfResponse(string $pdf, string $filename, array $headers = []): Response
    {
        return response($pdf, 200, $headers + [
            'Content-Type'                  => 'application/pdf',
            'Content-Disposition'           => 'inline; filename="' . $filename . '"',
            'Content-Length'                => (string) strlen($pdf),
            'Cache-Control'                 => 'private, no-store, max-age=0',
            'X-Content-Type-Options'        => 'nosniff',
        ]);
    }

    public static function intigoFailed(SellerOrder $so, \Throwable $e): JsonResponse
    {
        $timeout = $e instanceof IntigoException && $e->kind === 'connection';
        Log::warning("[Bordereau] seller_order {$so->id}: " . ($e instanceof IntigoException ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage()));
        return response()->json([
            'success' => false,
            'code'    => $timeout ? 'intigo_timeout' : 'intigo_error',
            'message' => __($timeout ? 'seller.bordereau_intigo_timeout' : 'seller.bordereau_intigo_error'),
        ], $timeout ? 504 : 502);
    }

    private function error(string $message, int $status, ?string $code = null, array $extra = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message] + ($code ? ['code' => $code] : []) + $extra, $status);
    }
}
