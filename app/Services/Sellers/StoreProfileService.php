<?php

namespace App\Services\Sellers;

use App\Models\SellerApplication;
use App\Models\SellerProfileChange;
use App\Models\User;
use App\Notifications\Sellers\SellerStoreProfileChangedNotification;
use App\Services\Search\Shops;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Store profile a seller edits from "Paramètres de la boutique": logo,
 * description and activity categories. The values live on the seller's
 * approved application (profile_picture, business_description,
 * business_categories), so the storefront, search, shop overview and admin
 * pages pick them up with no other change. The store name stays admin-only.
 *
 * Every change is logged in seller_profile_changes (old → new) and notifies the
 * admins (bell + e-mail). Old logo files are kept so the admin can restore them.
 *
 * Activity categories are information only: they never change the platform
 * categories, and products can use any platform category whatever is chosen here.
 */
class StoreProfileService
{
    public const MAX_CATEGORIES      = 5;      // same as the application
    public const MAX_DESCRIPTION     = 2000;   // same as the application
    public const MAX_LOGO_KB         = 4096;   // same as the application
    public const LOGO_SIZE           = 512;    // stored square side (px), never upscaled
    public const LOGO_MIN_SIDE       = 64;
    public const CHANGES_PER_HOUR    = 5;      // per section (logo / description / categories)
    private const LOGO_DIR           = 'seller-logos';

    // ── Reads ────────────────────────────────────────────────────────────────

    public static function application(int $sellerId): ?SellerApplication
    {
        return SellerApplication::where('user_id', $sellerId)->approved()->latest()->first();
    }

    /** Active platform categories (the list of the seller application), in display order. */
    public function platformCategories(): array
    {
        return DB::table('categories')->where('is_active', true)->orderBy('order')->orderBy('id')
            ->get(['id', 'name', 'name_fr', 'name_ar', 'slug'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'name_fr' => $c->name_fr, 'name_ar' => $c->name_ar, 'slug' => $c->slug])
            ->all();
    }

    /** French label of a stored category name (admin notices are in French). */
    public function categoryLabel(string $name): string
    {
        $fr = DB::table('categories')->where('name', $name)->value('name_fr');
        return $fr ?: $name;
    }

    public static function logoUrl(?string $path): ?string
    {
        if (!$path) return null;
        return preg_match('#^https?://#', $path) ? $path : Storage::disk('public')->url($path);
    }

    public function present(?SellerApplication $app): array
    {
        return [
            'business_name'        => $app?->business_name,
            'logo'                 => self::logoUrl($app?->profile_picture),
            'business_description' => $app?->business_description,
            'business_categories'  => array_values($app?->business_categories ?: array_filter([$app?->business_category])),
        ];
    }

    // ── Rate limit ───────────────────────────────────────────────────────────

    private function limiterKey(int $sellerId, string $field): string
    {
        return "store-profile:{$field}:{$sellerId}";
    }

    /** Throws a 429-style validation error once the seller made too many changes this hour. */
    public function ensureNotThrottled(int $sellerId, string $field): void
    {
        $key = $this->limiterKey($sellerId, $field);
        if (RateLimiter::tooManyAttempts($key, self::CHANGES_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            throw ValidationException::withMessages([
                $field => [__('seller.store.too_many', ['minutes' => $minutes])],
            ])->status(429);
        }
    }

    private function hit(int $sellerId, string $field): void
    {
        RateLimiter::hit($this->limiterKey($sellerId, $field), 3600);
    }

    // ── Logo ─────────────────────────────────────────────────────────────────

    /**
     * Decodes the upload, crops it to a centred square, scales it down to
     * LOGO_SIZE and re-encodes it (WebP with transparency, else JPEG). The
     * re-encode drops EXIF / metadata and anything hidden in the original file.
     */
    public function storeLogoFile(int $sellerId, UploadedFile $file): string
    {
        $info = @getimagesize($file->getRealPath());
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['logo' => [__('seller.store.logo_type')]]);
        }
        if (min($info[0], $info[1]) < self::LOGO_MIN_SIDE) {
            throw ValidationException::withMessages(['logo' => [__('seller.store.logo_small', ['px' => self::LOGO_MIN_SIDE])]]);
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (!$image) {
            throw ValidationException::withMessages(['logo' => [__('seller.store.logo_unreadable')]]);
        }

        // Phone photos: honour the EXIF orientation before it is dropped
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($file->getRealPath())['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle) {
                $rotated = imagerotate($image, $angle, 0);
                if ($rotated) { imagedestroy($image); $image = $rotated; }
            }
        }

        $w    = imagesx($image);
        $h    = imagesy($image);
        $side = min($w, $h);
        $size = min(self::LOGO_SIZE, $side);
        $webp = function_exists('imagewebp');

        $out = imagecreatetruecolor($size, $size);
        if ($webp) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        } else {
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // JPEG: flatten transparency
        }
        imagecopyresampled($out, $image, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);

        ob_start();
        $webp ? imagewebp($out, null, 85) : imagejpeg($out, null, 86);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($out);

        $path = self::LOGO_DIR . '/' . $sellerId . '/' . Str::uuid() . ($webp ? '.webp' : '.jpg');
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    public function updateLogo(User $seller, SellerApplication $app, UploadedFile $file): SellerProfileChange
    {
        $this->ensureNotThrottled($seller->id, SellerProfileChange::LOGO);
        $path = $this->storeLogoFile($seller->id, $file);

        // The previous file is kept (history / restore), never deleted
        $change = $this->apply($app, $seller, SellerProfileChange::LOGO,
            ['path' => $app->profile_picture], ['path' => $path]);
        $this->hit($seller->id, SellerProfileChange::LOGO);

        return $change;
    }

    // ── Description ──────────────────────────────────────────────────────────

    /** Plain text: tags removed, line breaks kept (\n), at most 2 empty lines in a row. */
    public static function cleanDescription(?string $text): ?string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = strip_tags($text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';            // control characters
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        $text = trim($text);
        return $text === '' ? null : $text;
    }

    /** @return SellerProfileChange|null null when the text did not change */
    public function updateDescription(User $seller, SellerApplication $app, ?string $text): ?SellerProfileChange
    {
        $text = self::cleanDescription($text);
        if ($text !== null && mb_strlen($text) > self::MAX_DESCRIPTION) {
            throw ValidationException::withMessages(['business_description' => [
                __('validation.max.string', ['attribute' => __('seller.store.description_attribute'), 'max' => self::MAX_DESCRIPTION]),
            ]]);
        }
        if ($text === self::cleanDescription($app->business_description)) {
            return null;
        }

        $this->ensureNotThrottled($seller->id, SellerProfileChange::DESCRIPTION);
        $change = $this->apply($app, $seller, SellerProfileChange::DESCRIPTION,
            ['text' => $app->business_description], ['text' => $text]);
        $this->hit($seller->id, SellerProfileChange::DESCRIPTION);

        return $change;
    }

    // ── Activity categories ──────────────────────────────────────────────────

    /**
     * @param string[] $names stored (English) category names, in order
     * @return SellerProfileChange|null null when nothing changed
     */
    public function updateCategories(User $seller, SellerApplication $app, array $names): ?SellerProfileChange
    {
        $names   = array_values(array_unique(array_map(fn ($n) => trim((string) $n), $names)));
        $current = array_values($app->business_categories ?: array_filter([$app->business_category]));

        if (count($names) === 0) {
            throw ValidationException::withMessages(['categories' => [__('seller.store.categories_min')]]);
        }
        if (count($names) > self::MAX_CATEGORIES) {
            throw ValidationException::withMessages(['categories' => [__('seller.store.categories_max', ['max' => self::MAX_CATEGORIES])]]);
        }

        // New ones must come from the platform list; ones already on the profile may stay
        $platform = array_column($this->platformCategories(), 'name');
        $unknown  = array_values(array_diff($names, $platform, $current));
        if ($unknown) {
            throw ValidationException::withMessages(['categories' => [__('seller.store.category_unknown', ['name' => $unknown[0]])]]);
        }

        $added   = array_values(array_diff($names, $current));
        $removed = array_values(array_diff($current, $names));
        if (!$added && !$removed && $names === $current) {
            return null;
        }

        $this->ensureNotThrottled($seller->id, SellerProfileChange::CATEGORIES);

        // Subcategories of a removed category go with it
        $subcats = $app->business_subcategories ?? [];
        if ($removed && $subcats) {
            $drop = DB::table('subcategories')->join('categories', 'categories.id', '=', 'subcategories.category_id')
                ->whereIn('categories.name', $removed)->pluck('subcategories.name')->all();
            $subcats = array_values(array_diff($subcats, $drop));
        }

        $change = $this->apply($app, $seller, SellerProfileChange::CATEGORIES,
            ['list' => $current],
            ['list' => $names, 'added' => $added, 'removed' => $removed],
            ['business_subcategories' => $subcats]);
        $this->hit($seller->id, SellerProfileChange::CATEGORIES);

        return $change;
    }

    // ── Admin restore ────────────────────────────────────────────────────────

    /** Puts back the value a logo / description change replaced. */
    public function restore(User $admin, SellerProfileChange $change): SellerProfileChange
    {
        if (!in_array($change->field, [SellerProfileChange::LOGO, SellerProfileChange::DESCRIPTION], true)) {
            throw ValidationException::withMessages(['change' => ['Only a logo or description change can be restored.']]);
        }
        $app = self::application($change->user_id);
        if (!$app) {
            throw ValidationException::withMessages(['change' => ['This seller has no approved application.']]);
        }

        if ($change->field === SellerProfileChange::LOGO) {
            $old = $change->old_value['path'] ?? null;
            if (!$old) {
                throw ValidationException::withMessages(['change' => ['There was no logo before this change.']]);
            }
            if (!preg_match('#^https?://#', $old) && !Storage::disk('public')->exists($old)) {
                throw ValidationException::withMessages(['change' => ['The old logo file no longer exists.']]);
            }
            return $this->apply($app, $admin, SellerProfileChange::LOGO,
                ['path' => $app->profile_picture], ['path' => $old], [], $change->id);
        }

        return $this->apply($app, $admin, SellerProfileChange::DESCRIPTION,
            ['text' => $app->business_description], ['text' => $change->old_value['text'] ?? null], [], $change->id);
    }

    // ── History ──────────────────────────────────────────────────────────────

    /**
     * One timeline per seller: logo / description / categories changes plus the
     * pickup address changes, newest first.
     */
    public function history(int $sellerId, int $limit = 50): array
    {
        $current = self::application($sellerId);

        $profile = SellerProfileChange::with('author:id,name')->where('user_id', $sellerId)->orderByDesc('id')->limit($limit)->get()
            ->map(function (SellerProfileChange $c) use ($current) {
                $old = $c->old_value ?? [];
                $new = $c->new_value ?? [];
                if ($c->field === SellerProfileChange::LOGO) {
                    $old['url'] = self::logoUrl($old['path'] ?? null);
                    $new['url'] = self::logoUrl($new['path'] ?? null);
                }
                $restorable = in_array($c->field, [SellerProfileChange::LOGO, SellerProfileChange::DESCRIPTION], true)
                    && $current !== null
                    && ($c->field === SellerProfileChange::LOGO
                        ? !empty($old['path']) && $old['path'] !== $current->profile_picture
                        : ($old['text'] ?? null) !== $current->business_description);

                return [
                    'id'          => $c->id,
                    'kind'        => 'profile',
                    'field'       => $c->field,
                    'at'          => $c->created_at?->toIso8601String(),
                    'by'          => $c->author?->name,
                    'by_role'     => $c->changed_by_role,
                    'old'         => $old,
                    'new'         => $new,
                    'restored_from_id' => $c->restored_from_id,
                    'restorable'  => $restorable,
                ];
            });

        $pickup = collect(app(PickupAddressService::class)->history($sellerId, $limit))
            ->map(fn (array $h) => [
                'id'          => $h['id'],
                'kind'        => 'pickup',
                'field'       => 'pickup_address',
                'at'          => $h['at'],
                'by'          => $h['by'],
                'by_role'     => $h['by_role'],
                'old'         => $h['old'],
                'new'         => $h['new'],
                'changed_fields' => $h['changed_fields'],
                'restorable'  => false,
            ]);

        return $profile->concat($pickup)->sortByDesc('at')->take($limit)->values()->all();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function apply(SellerApplication $app, User $actor, string $field, array $old, array $new, array $extra = [], ?int $restoredFrom = null): SellerProfileChange
    {
        $column = [
            SellerProfileChange::LOGO        => ['profile_picture' => $new['path'] ?? null],
            SellerProfileChange::DESCRIPTION => ['business_description' => $new['text'] ?? null],
            SellerProfileChange::CATEGORIES  => [
                'business_categories' => $new['list'] ?? [],
                'business_category'   => ($new['list'] ?? [])[0] ?? $app->business_category,
            ],
        ][$field];

        $change = DB::transaction(function () use ($app, $actor, $field, $old, $new, $extra, $restoredFrom, $column) {
            $app->forceFill($column + $extra)->save();   // never touches status / business_name

            return SellerProfileChange::create([
                'user_id'          => $app->user_id,
                'changed_by'       => $actor->id,
                'changed_by_role'  => $actor->role === 'admin' ? 'admin' : 'seller',
                'field'            => $field,
                'old_value'        => $old,
                'new_value'        => $new,
                'restored_from_id' => $restoredFrom,
            ]);
        });

        $this->forgetCaches();
        if ($actor->role !== 'admin') {
            $this->notifyAdmins($app, $change);
        }

        return $change;
    }

    /** Shop lists cached for a few minutes: the new logo shows everywhere right away. */
    private function forgetCaches(): void
    {
        try {
            Cache::forget('shop:overview:sellers');
            Cache::forget('platform:seller-showcase:12');
            app(Shops::class)->forget();
        } catch (\Throwable $e) {
            Log::warning('[StoreProfile] cache forget failed: ' . $e->getMessage());
        }
    }

    private function notifyAdmins(SellerApplication $app, SellerProfileChange $change): void
    {
        try {
            Notification::send(User::getAllAdmins(), new SellerStoreProfileChangedNotification($app, $change, $this));
        } catch (\Throwable $e) {
            // The change is saved and logged; the bell / mail must not undo it
            Log::error("[StoreProfile] admin notice for seller {$app->user_id} failed: " . $e->getMessage());
        }
    }
}
