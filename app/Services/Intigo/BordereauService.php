<?php

namespace App\Services\Intigo;

use App\Models\SellerOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Sellers (and admins) download a parcel's bordereau. Two sources:
 *
 *   manual  the admin uploaded the PDF (BordereauUploads): works whatever INTIGO_BORDEREAU_ENABLED says
 *   intigo  fetched from Intigo's API, only while INTIGO_BORDEREAU_ENABLED=true
 *
 *   pdf()     the uploaded file, else the cached Intigo copy (private/bordereaux/{id}-{nid}.pdf),
 *             else fetched from Intigo (BordereauClient) and cached.
 *   record()  audit row in bordereau_downloads; seller downloads update the counters
 *             and the FIRST one per file tells the admins (bell + e-mail).
 *   merge()   several bordereaux in one PDF (bulk print).
 *
 * Read-only towards the parcel flow: never touches the intigo_* columns.
 */
class BordereauService
{
    public function __construct(private BordereauClient $client, private IntigoAlerts $alerts) {}

    /** A live Intigo parcel exists (same rule as the seller's delivery state "sent"). */
    public static function available(SellerOrder $so): bool
    {
        return (bool) $so->intigo_nid && $so->intigo_sync_status !== ParcelSender::CANCELLED;
    }

    /** Feature flag INTIGO_BORDEREAU_ENABLED: the Intigo API source only (manual uploads ignore it). */
    public static function enabledForSellers(): bool
    {
        return (bool) config('intigo.bordereau.enabled', false);
    }

    /** The admin uploaded a PDF for this seller_order. */
    public static function hasManual(SellerOrder $so): bool
    {
        return $so->bordereau_source === 'manual' && filled($so->bordereau_path);
    }

    /** What the seller can print: an uploaded PDF, or (flag on) the Intigo one. */
    public static function availableToSeller(SellerOrder $so): bool
    {
        return self::hasManual($so) || (self::enabledForSellers() && self::available($so));
    }

    /** @throws IntigoException kind "missing" when the uploaded file is gone */
    public function pdf(SellerOrder $so): string
    {
        $disk = Storage::disk('local');
        if (self::hasManual($so)) {
            $bytes = $disk->exists($so->bordereau_path) ? $disk->get($so->bordereau_path) : '';
            if (!str_starts_with($bytes, '%PDF')) {
                throw new IntigoException("Uploaded bordereau file missing for seller_order {$so->id}", null, false, 'missing');
            }
            return $bytes;
        }
        $path = trim((string) config('intigo.bordereau.cache_dir', 'private/bordereaux'), '/')
            . '/' . $so->id . '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $so->intigo_nid) . '.pdf';

        if ($so->bordereau_path === $path && $disk->exists($path)) {
            $cached = $disk->get($path);
            if (str_starts_with($cached, '%PDF')) {
                return $cached;
            }
        }

        $pdf = $this->client->pdf((string) $so->intigo_nid, $so->id);
        try {
            $disk->put($path, $pdf);
            DB::table('seller_orders')->where('id', $so->id)->update(['bordereau_path' => $path, 'bordereau_source' => 'intigo']);
            $so->bordereau_path   = $path;
            $so->bordereau_source = 'intigo';
        } catch (\Throwable $e) {
            // Serving the PDF matters more than caching it
            Log::warning("[Bordereau] could not cache seller_order {$so->id}: " . $e->getMessage());
        }
        return $pdf;
    }

    /** "bordereau-ORD-123.pdf" (one seller_order per seller and order, so unique for that seller). */
    public function filename(SellerOrder $so): string
    {
        $number = DB::table('orders')->where('id', $so->order_id)->value('order_number') ?: ('S' . $so->id);
        return 'bordereau-' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $number) . '.pdf';
    }

    /**
     * Logs one download. Never throws: a logging problem must not block the print.
     *
     * @param string $source seller | admin (admin downloads: logged only, no counters, no notification)
     */
    public function record(SellerOrder $so, ?User $user, Request $request, string $source = 'seller'): void
    {
        try {
            $now   = now();
            $first = DB::transaction(function () use ($so, $user, $request, $source, $now) {
                DB::table('bordereau_downloads')->insert([
                    'seller_order_id' => $so->id,
                    'seller_id'       => $so->seller_id,
                    'user_id'         => $user?->id,
                    'source'          => $source,
                    'ip'              => mb_substr((string) $request->ip(), 0, 45),
                    'user_agent'      => mb_substr((string) $request->userAgent(), 0, 500),
                    'created_at'      => $now,
                ]);
                if ($source !== 'seller') {
                    return false;
                }
                $first = DB::table('seller_orders')->where('id', $so->id)->whereNull('bordereau_first_downloaded_at')
                    ->update(['bordereau_first_downloaded_at' => $now]) === 1;
                DB::table('seller_orders')->where('id', $so->id)->update([
                    'bordereau_last_downloaded_at' => $now,
                    'bordereau_download_count'     => DB::raw('bordereau_download_count + 1'),
                ]);
                return $first;
            });

            if ($first) {
                $shop   = $user ? ($user->storefrontBranding()['business_name'] ?? $user->name) : ('Seller #' . $so->seller_id);
                $number = DB::table('orders')->where('id', $so->order_id)->value('order_number') ?: ('#' . $so->order_id);
                // Bell to every admin + e-mail to intigo.alert_email, once per parcel and file
                // (a replaced upload is a new file: its first print is told again)
                $version = $so->bordereau_source === 'manual' && $so->bordereau_upload_id ? 'u' . $so->bordereau_upload_id : null;
                $this->alerts->inform($so, 'bordereau_printed', 'Bordereau printed',
                    "{$shop} printed the bordereau for order #{$number}.", true, $version);
            }
        } catch (\Throwable $e) {
            Log::warning("[Bordereau] download of seller_order {$so->id} not recorded: " . $e->getMessage());
        }
    }

    /**
     * One PDF with every page of each bordereau, each page at its own size.
     *
     * @param string[] $pdfs
     * @throws \Throwable when a PDF can't be read (Intigo format the free parser doesn't support)
     */
    public function merge(array $pdfs): string
    {
        $tmp = storage_path('app/mpdf');
        if (!is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $mpdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => $tmp]);
        $mpdf->SetTitle('Bordereaux');
        foreach ($pdfs as $bytes) {
            $pages = $mpdf->setSourceFile(StreamReader::createByString($bytes));
            for ($p = 1; $p <= $pages; $p++) {
                $tpl  = $mpdf->importPage($p);
                $size = $mpdf->getTemplateSize($tpl);
                $mpdf->AddPageByArray([
                    'orientation' => $size['orientation'],
                    'sheet-size'  => [$size['width'], $size['height']],
                    'margin-left' => 0, 'margin-right' => 0, 'margin-top' => 0, 'margin-bottom' => 0,
                ]);
                $mpdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
            }
        }
        return $mpdf->Output('', 'S');
    }
}
