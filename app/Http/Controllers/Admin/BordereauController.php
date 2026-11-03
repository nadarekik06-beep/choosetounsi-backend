<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\Seller\SellerBordereauController;
use App\Http\Controllers\Controller;
use App\Models\SellerOrder;
use App\Services\Intigo\BordereauService;
use App\Services\Intigo\BordereauUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin side of a seller_order's bordereau (one per seller_order).
 *
 *   GET    /api/admin/seller-orders/{id}/bordereau          preview: the uploaded PDF, else (flag on) Intigo's.
 *                                                           Logged (source admin), never counted as the seller's print.
 *   POST   /api/admin/seller-orders/{id}/bordereau          multipart "file": upload or replace (PDF, max 5 MB); seller notified
 *   DELETE /api/admin/seller-orders/{id}/bordereau          remove (history kept); the seller no longer sees it
 *   POST   /api/admin/seller-orders/{id}/bordereau/notify   resend the seller notification
 */
class BordereauController extends Controller
{
    public function show(Request $request, int $id, BordereauService $bordereaux): Response
    {
        $so = SellerOrder::findOrFail($id);
        $intigo = BordereauService::enabledForSellers() && BordereauService::available($so);
        if (!BordereauService::hasManual($so) && !$intigo) {
            return response()->json(['success' => false, 'code' => 'not_available', 'message' => 'No bordereau for this order yet.'], 404);
        }
        try {
            $pdf = $bordereaux->pdf($so);
        } catch (\Throwable $e) {
            return SellerBordereauController::intigoFailed($so, $e);
        }
        $bordereaux->record($so, $request->user(), $request, 'admin');
        return SellerBordereauController::pdfResponse($pdf, $bordereaux->filename($so));
    }

    public function store(Request $request, int $id, BordereauUploads $uploads): JsonResponse
    {
        $so = SellerOrder::findOrFail($id);
        if ($reason = BordereauUploads::blockReason($so)) {
            return response()->json(['success' => false, 'code' => 'not_allowed', 'message' => $reason], 422);
        }
        $request->validate([
            'file' => 'required|file|mimes:pdf|mimetypes:application/pdf|max:' . BordereauUploads::MAX_KB,
        ], [
            'file.mimes'     => 'The bordereau must be a PDF file.',
            'file.mimetypes' => 'The bordereau must be a PDF file.',
            'file.max'       => 'The bordereau must not be larger than 5 MB.',
        ]);
        $file = $request->file('file');
        if (!BordereauUploads::isPdf($file)) {
            return response()->json(['success' => false, 'message' => 'This file is not a real PDF.', 'errors' => ['file' => ['This file is not a real PDF.']]], 422);
        }

        $replaced = $uploads->upload($so, $file, $request->user());
        $fresh = SellerOrder::findOrFail($id);
        return response()->json([
            'success' => true,
            'message' => $replaced ? 'Bordereau replaced. The seller was told to print the new one.' : 'Bordereau attached. The seller was notified.',
            'data'    => IntigoController::bordereauSummary($fresh),
        ]);
    }

    public function destroy(Request $request, int $id, BordereauUploads $uploads): JsonResponse
    {
        $so = SellerOrder::findOrFail($id);
        if (!BordereauService::hasManual($so)) {
            return response()->json(['success' => false, 'message' => 'No uploaded bordereau to remove.'], 404);
        }
        $uploads->remove($so, $request->user());
        return response()->json(['success' => true, 'message' => 'Bordereau removed. The seller no longer sees it.', 'data' => IntigoController::bordereauSummary(SellerOrder::findOrFail($id))]);
    }

    public function notify(int $id, BordereauUploads $uploads): JsonResponse
    {
        $so = SellerOrder::findOrFail($id);
        if (!BordereauService::hasManual($so)) {
            return response()->json(['success' => false, 'message' => 'Upload a bordereau first.'], 404);
        }
        $updated = \Illuminate\Support\Facades\DB::table('bordereau_uploads')->where('seller_order_id', $so->id)->count() > 1;
        $sent = $uploads->notify($so, $updated, true);
        $fresh = SellerOrder::findOrFail($id);
        return response()->json([
            'success' => $sent,
            'message' => $sent ? 'Notification sent again.' : ($fresh->bordereau_notify_error ?: 'The notification could not be sent.'),
            'data'    => IntigoController::bordereauSummary($fresh),
        ], $sent ? 200 : 422);
    }
}
