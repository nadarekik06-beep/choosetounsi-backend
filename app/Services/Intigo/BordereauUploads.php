<?php

namespace App\Services\Intigo;

use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Orders\SellerOrderNotifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manual bordereau: the admin uploads the PDF he downloaded from the Intigo dashboard,
 * one per seller_order. The file becomes the seller_order's current bordereau
 * (bordereau_source = manual, bordereau_path), every file is kept in bordereau_uploads.
 *
 *   upload()  first file or a replacement (old one marked replaced, "seen" state reset)
 *   remove()  the seller no longer sees it (history kept)
 *   notify()  SellerBordereauReadyNotification ("ready" / "updated"), once per file;
 *             resend = a new notification for the same file
 *
 * Independent of INTIGO_BORDEREAU_ENABLED and of the parcel flow (no intigo_* column touched).
 */
class BordereauUploads
{
    /** Statuses a bordereau can be attached to (not cancelled, delivered or returned). */
    public const STATUSES = ['confirmed', 'handed_to_courier', 'out_for_delivery'];

    public const MAX_KB = 5120;

    public function __construct(private SellerOrderNotifier $notifier) {}

    /** Why no bordereau can be attached now, or null when it can. */
    public static function blockReason(SellerOrder $so): ?string
    {
        if (!in_array($so->status, self::STATUSES, true)) {
            return "This order is {$so->status}: a bordereau can only be attached to a prepared order that is not cancelled, delivered or returned.";
        }
        if ($so->status === 'confirmed' && !$so->prepared_at && !$so->intigo_nid) {
            return 'The seller has not marked this order as prepared yet.';
        }
        return null;
    }

    public static function isPdf(UploadedFile $file): bool
    {
        $h = @fopen($file->getRealPath(), 'rb');
        if (!$h) {
            return false;
        }
        $head = fread($h, 5);
        fclose($h);
        return $head !== false && str_starts_with($head, '%PDF');
    }

    /** @return bool true when it replaced an earlier file */
    public function upload(SellerOrder $so, UploadedFile $file, User $admin): bool
    {
        $dir  = trim((string) config('intigo.bordereau.cache_dir', 'private/bordereaux'), '/') . '/manual';
        $path = "{$dir}/{$so->id}-" . now()->format('YmdHis') . '-' . Str::lower(Str::random(6)) . '.pdf';
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $name     = mb_substr(basename((string) $file->getClientOriginalName()), 0, 255) ?: 'bordereau.pdf';
        $replaced = DB::transaction(function () use ($so, $path, $name, $file, $admin) {
            $row = SellerOrder::whereKey($so->id)->lockForUpdate()->firstOrFail();
            $replaced = $row->bordereau_source === 'manual' && $row->bordereau_upload_id;
            if ($replaced) {
                DB::table('bordereau_uploads')->where('id', $row->bordereau_upload_id)->update(['replaced_at' => now()]);
            }
            $uploadId = DB::table('bordereau_uploads')->insertGetId([
                'seller_order_id' => $so->id,
                'path'            => $path,
                'original_name'   => $name,
                'size'            => (int) $file->getSize(),
                'uploaded_by'     => $admin->id,
                'created_at'      => now(),
            ]);
            DB::table('seller_orders')->where('id', $so->id)->update([
                'bordereau_source'              => 'manual',
                'bordereau_path'                => $path,
                'bordereau_upload_id'           => $uploadId,
                'bordereau_uploaded_at'         => now(),
                'bordereau_uploaded_by'         => $admin->id,
                'bordereau_original_name'       => $name,
                // A new file: the seller has not been told nor opened it yet
                'bordereau_seller_notified_at'  => null,
                'bordereau_notify_error'        => null,
                'bordereau_first_downloaded_at' => null,
                'bordereau_last_downloaded_at'  => null,
                'bordereau_download_count'      => 0,
            ]);
            return (bool) $replaced;
        });

        Log::info("[Bordereau] seller_order {$so->id}: " . ($replaced ? 'replaced' : 'uploaded') . " by admin {$admin->id}");
        $this->notify($so->fresh(), $replaced);
        return $replaced;
    }

    public function remove(SellerOrder $so, User $admin): void
    {
        DB::transaction(function () use ($so, $admin) {
            $row = SellerOrder::whereKey($so->id)->lockForUpdate()->firstOrFail();
            if ($row->bordereau_upload_id) {
                DB::table('bordereau_uploads')->where('id', $row->bordereau_upload_id)
                    ->update(['removed_at' => now(), 'removed_by' => $admin->id]);
            }
            DB::table('seller_orders')->where('id', $so->id)->update([
                'bordereau_source'              => null,
                'bordereau_path'                => null,
                'bordereau_upload_id'           => null,
                'bordereau_uploaded_at'         => null,
                'bordereau_uploaded_by'         => null,
                'bordereau_original_name'       => null,
                'bordereau_seller_notified_at'  => null,
                'bordereau_notify_error'        => null,
                'bordereau_first_downloaded_at' => null,
                'bordereau_last_downloaded_at'  => null,
                'bordereau_download_count'      => 0,
            ]);
        });
        Log::info("[Bordereau] seller_order {$so->id}: removed by admin {$admin->id}");
    }

    /**
     * Tells the seller the current file is there. Once per file; $resend sends it again.
     * bordereau_seller_notified_at is set when the bell entry is saved (see the notification).
     */
    public function notify(SellerOrder $so, bool $updated = false, bool $resend = false): bool
    {
        if (!BordereauService::hasManual($so)) {
            return false;
        }
        if ($so->seller_id === null || $so->isPlatformParcel()) {
            DB::table('seller_orders')->where('id', $so->id)->update(['bordereau_notify_error' => 'Platform parcel: no seller to notify.']);
            return false;
        }
        DB::table('seller_orders')->where('id', $so->id)->update(['bordereau_notify_error' => null]);

        // seller_order_notifications.event (32 chars): one claim per file, or per resend
        $claim = 'bdx:' . $so->bordereau_upload_id . ($resend ? ':' . now()->format('ymdHis') : '');
        try {
            $sent = $this->notifier->bordereauReady($so, $updated, $claim);
        } catch (\Throwable $e) {
            $sent = false;
            Log::error("[Bordereau] notify seller_order {$so->id} failed: " . $e->getMessage());
        }
        if (!$sent && $resend) {
            DB::table('seller_orders')->where('id', $so->id)->update(['bordereau_notify_error' => 'The notification could not be queued. Try again.']);
        }
        return $sent;
    }
}
