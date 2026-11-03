<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\Seller\SellerBordereauController;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\Intigo\BordereauService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/admin/seller-orders/{id}/bordereau: any parcel's Intigo bordereau.
 * Same cache as the seller's download; logged in bordereau_downloads (source admin)
 * but never counted as the seller's print and never notified.
 */
class BordereauController extends Controller
{
    public function show(Request $request, int $id, BordereauService $bordereaux): Response
    {
        $so = SellerOrder::findOrFail($id);
        if (!BordereauService::available($so)) {
            return response()->json(['success' => false, 'code' => 'no_parcel', 'message' => 'No Intigo parcel for this order yet.'], 409);
        }
        try {
            $pdf = $bordereaux->pdf($so);
        } catch (\Throwable $e) {
            return SellerBordereauController::intigoFailed($so, $e);
        }
        $bordereaux->record($so, $request->user(), $request, 'admin');
        return SellerBordereauController::pdfResponse($pdf, $bordereaux->filename($so));
    }
}
