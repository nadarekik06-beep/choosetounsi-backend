<?php

namespace App\Services\Intigo;

/**
 * Downloads a parcel's Intigo bordereau (the label the courier scans), as a PDF.
 * Bound in AppServiceProvider: HttpBordereauClient in live mode,
 * FakeBordereauClient otherwise (a local test PDF, no network).
 *
 * Kept apart from IntigoClient so the parcel flow and its test doubles are untouched.
 * Failures throw IntigoException.
 */
interface BordereauClient
{
    public function mode(): string;

    /** The PDF bytes (always starting with %PDF). */
    public function pdf(string $nid, ?int $sellerOrderId = null): string;
}
