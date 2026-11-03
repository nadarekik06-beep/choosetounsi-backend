<?php

namespace App\Services\Intigo;

use Mpdf\Mpdf;

/**
 * Fake mode: a local one-page test PDF marked "TEST — not valid for delivery",
 * with the nid as a barcode. No network, no buyer data. Logged in intigo_logs
 * (mode fake) like the live call.
 */
class FakeBordereauClient implements BordereauClient
{
    public function __construct(private IntigoLogger $logger) {}

    public function mode(): string
    {
        return 'fake';
    }

    public function pdf(string $nid, ?int $sellerOrderId = null): string
    {
        $tmp = storage_path('app/mpdf');
        if (!is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A5', 'tempDir' => $tmp, 'default_font' => 'dejavusans']);
        $mpdf->SetTitle('Bordereau TEST ' . $nid);
        $safe = e($nid);
        $mpdf->WriteHTML(
            '<div style="text-align:center;font-family:dejavusans;">'
            . '<div style="font-size:18pt;font-weight:bold;color:#b91c1c;">BORDEREAU DE TEST</div>'
            . '<div style="font-size:10pt;color:#6b7280;">INTIGO_MODE=fake · not valid for delivery</div>'
            . '<div style="margin-top:14mm;"><barcode code="' . $safe . '" type="C128B" size="1.2" height="1.4" /></div>'
            . '<div style="margin-top:3mm;font-size:16pt;font-weight:bold;">' . $safe . '</div>'
            . '<div style="margin-top:8mm;font-size:10pt;">Seller order #' . (int) $sellerOrderId . '</div>'
            . '</div>'
        );
        $pdf = $mpdf->Output('', 'S');

        $this->logger->log('fake', 'GET', '/secure-api/bordereaux/' . rawurlencode($nid), null, 200,
            ['content_type' => 'application/pdf', 'bytes' => strlen($pdf)], 0, $sellerOrderId);

        return $pdf;
    }
}
