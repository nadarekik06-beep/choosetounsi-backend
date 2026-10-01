<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderExport;
use App\Services\Orders\DeliveryDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivery documents (PDF) for the courier + the internal admin summary.
 *
 *   GET  /api/admin/orders/{id}/export/slips/{sellerOrderId}  one sub-order's slip
 *   GET  /api/admin/orders/{id}/export/slips                  every slip of the order
 *   GET  /api/admin/orders/{id}/export/summary                INTERNAL summary
 *   POST /api/admin/orders/export/slips   {order_ids: []}     slips of many orders
 *
 * Admin only (role:admin route group + the export-order-documents gate).
 * Slips refuse to generate (422 + issues) when the buyer address or a pickup
 * address is missing — a slip the courier can't use is worse than none.
 */
class OrderExportController extends Controller
{
    private const BULK_MAX = 100;

    public function __construct(private DeliveryDocumentService $documents) {}

    public function slip(Request $request, int $id, int $sellerOrderId)
    {
        $this->authorize('export-order-documents');

        $order       = $this->documents->query()->findOrFail($id);
        $sellerOrder = $this->documents->activeSellerOrders($order)->firstWhere('id', $sellerOrderId);
        abort_unless($sellerOrder, 404, 'Sub-order not found or not shippable (cancelled).');

        if ($issues = $this->documents->exportIssues($order, $sellerOrder)) {
            return $this->notReady($issues);
        }

        $pdf = $this->documents->slipsPdf([$this->documents->slip($order, $sellerOrder)], $this->documents->reference($order, $sellerOrder));
        $this->documents->log($order, OrderExport::TYPE_SLIP, $request->user()->id, $sellerOrder->id);

        return $this->download($pdf, $this->documents->slipFilename($order, $sellerOrder));
    }

    public function slips(Request $request, int $id)
    {
        $this->authorize('export-order-documents');

        $order = $this->documents->query()->findOrFail($id);

        if ($issues = $this->documents->exportIssues($order)) {
            return $this->notReady($issues);
        }

        $pdf = $this->documents->slipsPdf($this->documents->slipsFor($order), $order->order_number);
        $this->documents->log($order, OrderExport::TYPE_SLIPS, $request->user()->id);

        return $this->download($pdf, $this->documents->filenameBase($order) . '-slips.pdf');
    }

    public function summary(Request $request, int $id)
    {
        $this->authorize('export-order-documents');

        $order = $this->documents->query()->findOrFail($id);
        $pdf   = $this->documents->summaryPdf($order);
        $this->documents->log($order, OrderExport::TYPE_SUMMARY, $request->user()->id);

        return $this->download($pdf, $this->documents->filenameBase($order) . '-INTERNAL-summary.pdf');
    }

    public function bulk(Request $request)
    {
        $this->authorize('export-order-documents');

        $data = $request->validate([
            'order_ids'   => ['required', 'array', 'min:1', 'max:' . self::BULK_MAX],
            'order_ids.*' => ['integer', 'distinct'],
        ]);

        $orders = $this->documents->query()->whereIn('id', $data['order_ids'])->orderBy('id')->get();
        if ($orders->count() !== count($data['order_ids'])) {
            return response()->json(['success' => false, 'message' => 'Some orders were not found.'], 404);
        }

        $blocked = [];
        foreach ($orders as $order) {
            if ($issues = $this->documents->exportIssues($order)) {
                $blocked[$order->order_number] = $issues;
            }
        }
        if ($blocked) {
            return response()->json([
                'success' => false,
                'message' => count($blocked) . ' selected order(s) are missing address data. Fix or unselect them.',
                'blocked' => $blocked,
            ], 422);
        }

        $slips = $orders->flatMap(fn($o) => $this->documents->slipsFor($o))->all();
        $pdf   = $this->documents->slipsPdf($slips, 'Delivery slips');
        foreach ($orders as $order) {
            $this->documents->log($order, OrderExport::TYPE_BULK, $request->user()->id);
        }

        return $this->download($pdf, 'CT-delivery-slips-' . now()->format('Ymd-Hi') . '-' . $orders->count() . '-orders.pdf');
    }

    private function notReady(array $issues): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Cannot generate the delivery slip: ' . $issues[0],
            'issues'  => $issues,
        ], 422);
    }

    private function download(string $pdf, string $filename): Response
    {
        Log::info('[OrderExport] ' . $filename . ' by user ' . auth()->id());

        return response()->streamDownload(fn() => print($pdf), $filename, [
            'Content-Type'  => 'application/pdf',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
