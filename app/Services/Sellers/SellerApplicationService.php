<?php

namespace App\Services\Sellers;

use App\Mail\SellerApplicationApprovedMail;
use App\Mail\SellerApplicationSubmittedMail;
use App\Models\IntigoZone;
use App\Models\SellerApplication;
use App\Models\SellerPickupAddress;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\NewSellerApplicationNotification;
use App\Notifications\SellerApplicationReviewedNotification;
use App\Services\SubscriptionService;
use App\Support\SocialLink;
use App\Support\TunisianPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Seller applications: the one place that validates, creates and approves them.
 *
 *   create()   the user applying from the storefront, or an admin applying on a
 *              user's behalf (Admin > Users): same rules, same record, same pickup address
 *   approve()  Admin > Applications "Approve", or an admin-created application
 *              approved on the spot: role → seller, default plan, subscription, user told
 */
class SellerApplicationService
{
    public function __construct(private PickupAddressService $pickups) {}

    // ── Validation ─────────────────────────────────────────────────────────

    public static function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'full_name'                  => "{$required}|string|max:255",
            'phone_number'               => [...explode('|', $required), 'string', 'regex:' . TunisianPhone::PATTERN],
            'business_name'              => "{$required}|string|max:255",
            'business_category'          => "{$required}|string|max:255",
            'business_categories'        => 'nullable|array|max:5',
            'business_categories.*'      => 'string|max:255',
            'business_subcategories'     => 'nullable|array',
            'business_subcategories.*'   => 'string|max:255',
            'business_description'       => 'nullable|string|max:2000',
            // Pickup address (Intigo "Nouvelle adresse"): wilaya / city are derived from the zone
            ...PickupAddressService::rules(!$isUpdate),
            // Required by the storefront form only; optional for an admin (the seller adds them later)
            'profile_picture'            => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
            'sample_images'              => 'nullable|array|max:5',
            'sample_images.*'            => 'image|mimes:jpg,jpeg,png,webp|max:4096',
            'sample_captions'            => 'nullable|array|max:5',
            'sample_captions.*'          => 'nullable|string|max:200',
            // Handles and links without "https://" are turned into full links by normalize()
            'facebook_url'               => ['nullable', 'string', 'max:255', 'regex:' . SocialLink::PATTERN],
            'instagram_url'              => ['nullable', 'string', 'max:255', 'regex:' . SocialLink::PATTERN],
            'website_url'                => ['nullable', 'string', 'max:255', 'regex:' . SocialLink::PATTERN],
            'preferred_plan'             => 'nullable|in:green,red,black',
            'pricing_range'              => 'nullable|in:budget,mid,premium',
        ];
    }

    /** Normalizes the request (phone, links, text, address fields) and validates it. */
    public static function validate(Request $request, bool $isUpdate = false): array
    {
        PickupAddressService::prepare($request);
        self::normalize($request, $isUpdate);
        return $request->validate(self::rules($isUpdate), self::messages());
    }

    /**
     * Fixes what sellers type instead of refusing it (same rules as the storefront's
     * lib/sellerApplication/normalize.ts): phone "+216 22-123-456" → "22123456",
     * Instagram "@shop" → "https://instagram.com/shop", website "shop.tn" → "https://shop.tn",
     * spaces trimmed.
     */
    public static function normalize(Request $request, bool $isUpdate = false): void
    {
        $merge = [];
        if (!$isUpdate || $request->has('phone_number')) {
            $merge['phone_number'] = TunisianPhone::normalize($request->input('phone_number'));
        }
        foreach (['full_name', 'business_name', 'business_description'] as $key) {
            if (is_string($request->input($key))) {
                $merge[$key] = trim($request->input($key));
            }
        }
        foreach (['facebook_url' => 'facebook', 'instagram_url' => 'instagram'] as $key => $network) {
            if ($request->has($key)) {
                $merge[$key] = SocialLink::social($network, $request->input($key));
            }
        }
        if ($request->has('website_url')) {
            $merge['website_url'] = SocialLink::website($request->input('website_url'));
        }
        if (is_array($request->input('sample_captions'))) {
            $merge['sample_captions'] = array_map(fn ($c) => is_string($c) ? trim($c) : $c, $request->input('sample_captions'));
        }
        $request->merge($merge);
    }

    /** Plain-language messages a seller understands, one per rule (seller.application_form.*). */
    public static function messages(): array
    {
        $m = fn (string $key) => __("seller.application_form.{$key}");

        return [
            'full_name.required'          => $m('full_name_required'),
            'full_name.max'               => $m('full_name_max'),
            'phone_number.required'       => $m('phone_required'),
            'phone_number.regex'          => $m('phone_invalid'),
            'business_name.required'      => $m('business_name_required'),
            'business_name.max'           => $m('business_name_max'),
            'business_category.required'  => $m('category_required'),
            'business_categories.max'     => $m('categories_max'),
            'business_description.max'    => $m('description_max'),
            'profile_picture.image'       => $m('picture_type'),
            'profile_picture.mimes'       => $m('picture_type'),
            'profile_picture.max'         => $m('picture_heavy'),
            'profile_picture.uploaded'    => $m('picture_heavy'),
            'sample_images.max'           => $m('samples_max'),
            'sample_images.*.image'       => $m('sample_type'),
            'sample_images.*.mimes'       => $m('sample_type'),
            'sample_images.*.max'         => $m('sample_heavy'),
            'sample_images.*.uploaded'    => $m('sample_heavy'),
            'sample_captions.*.max'       => $m('caption_max'),
            'facebook_url.regex'          => $m('facebook_invalid'),
            'facebook_url.max'            => $m('link_too_long'),
            'instagram_url.regex'         => $m('instagram_invalid'),
            'instagram_url.max'           => $m('link_too_long'),
            'website_url.regex'           => $m('website_invalid'),
            'website_url.max'             => $m('link_too_long'),
            'pricing_range.in'            => $m('pricing_invalid'),
            'contact_name.required'       => $m('contact_required'),
            'contact_name.min'            => $m('contact_required'),
            'phone.required'              => $m('phone_required'),
            'phone.regex'                 => $m('phone_invalid'),
            'adresse_complete.required'   => $m('address_required'),
            'adresse_complete.min'        => $m('address_required'),
            'adresse_complete.max'        => $m('address_max'),
            'intigo_zone_id.required'     => $m('zone_required'),
            'code_postal.required'        => $m('postal_invalid'),
            'code_postal.digits'          => $m('postal_invalid'),
            ...PickupAddressService::messages(),
        ];
    }

    public static function resolveCategories(array $validated): array
    {
        $categories = $validated['business_categories']
            ?? [$validated['business_category']];

        return [
            'primary' => $categories[0] ?? $validated['business_category'] ?? '',
            'all'     => $categories,
            'subcats' => $validated['business_subcategories'] ?? [],
        ];
    }

    // ── Files ──────────────────────────────────────────────────────────────

    public static function storeProfilePicture(Request $request, ?string $existing = null): ?string
    {
        if ($request->hasFile('profile_picture')) {
            if ($existing) {
                Storage::disk('public')->delete($existing);
            }
            return $request->file('profile_picture')->store('seller-applications/profiles', 'public');
        }
        return $existing;
    }

    public static function storeSampleImages(Request $request, array $existing = []): array
    {
        if (!$request->hasFile('sample_images')) {
            return $existing;
        }
        $paths = $existing; // keep existing images, append new ones
        foreach ($request->file('sample_images') as $image) {
            $paths[] = $image->store('seller-applications/samples', 'public');
        }
        return $paths;
    }

    // ── Create ─────────────────────────────────────────────────────────────

    /** The user's pending application, if any (one at a time). */
    public function pendingFor(User $user): ?SellerApplication
    {
        return SellerApplication::where('user_id', $user->id)->where('status', 'pending')->first();
    }

    /**
     * Validates the request and creates a pending application with its pickup address.
     * $admin: the admin applying on the user's behalf (stored, and no "new application"
     * notice / "submitted" e-mail). The pickup address stays unverified either way.
     * Callers check pendingFor() first; validation errors throw before anything is saved.
     */
    public function create(User $user, Request $request, ?User $admin = null): SellerApplication
    {
        $validated = self::validate($request);
        $cats      = self::resolveCategories($validated);
        $zone      = IntigoZone::findOrFail($validated['intigo_zone_id']);

        $profilePicturePath = self::storeProfilePicture($request);
        $sampleImagePaths   = self::storeSampleImages($request);

        $application = DB::transaction(function () use ($user, $validated, $cats, $zone, $profilePicturePath, $sampleImagePaths, $admin) {
            $application = SellerApplication::create([
                'user_id'                => $user->id,
                'full_name'              => $validated['full_name'],
                'phone_number'           => $validated['phone_number'],
                'business_name'          => $validated['business_name'],
                'business_category'      => $cats['primary'],
                'business_categories'    => $cats['all'],
                'business_subcategories' => $cats['subcats'],
                'business_description'   => $validated['business_description'] ?? null,
                'wilaya'                 => $zone->wilaya ?: $zone->gouvernorat,
                'city'                   => $zone->delegation,
                'profile_picture'        => $profilePicturePath,
                'sample_images'          => $sampleImagePaths ?: null,
                'sample_captions'        => $validated['sample_captions'] ?? null,
                'facebook_url'           => $validated['facebook_url']  ?? null,
                'instagram_url'          => $validated['instagram_url'] ?? null,
                'website_url'            => $validated['website_url']   ?? null,
                'status'                 => 'pending',
                'preferred_plan'         => $validated['preferred_plan'] ?? 'green',
                'plan'                   => 'free',
                'pricing_range'          => $validated['pricing_range'] ?? null,
                'created_by_admin_id'    => $admin?->id,
            ]);

            // Same source as the user's own application: unverified, the admin checks it as usual
            $this->pickups->save($user, $validated, $admin ?? $user, 'application');

            return $application;
        });

        if (!$admin) {
            Notification::send(
                User::getAllAdmins(),
                new NewSellerApplicationNotification(
                    $application->id,
                    $validated['full_name'],
                    $validated['business_name'],
                    $user->id
                )
            );
            Mail::to($user)->queue(new SellerApplicationSubmittedMail($user, $application));
        }

        return $application;
    }

    // ── Approve ────────────────────────────────────────────────────────────

    /** What stops the approval: the courier must find the shop (zone + map pin). [] = nothing. */
    public function approvalBlockers(SellerApplication $application): array
    {
        $pickup = SellerPickupAddress::where('user_id', $application->user_id)->first();
        if (!$pickup || !$pickup->isLocated()) {
            return $this->pickups->missing($pickup) ?: ['pickup'];
        }
        return [];
    }

    /**
     * Approves the application: default plan, subscription row, user → active seller,
     * user notified (bell + e-mail). The role is read from the database on every
     * request, so the user's open sessions become seller sessions without a re-login.
     * Callers check approvalBlockers() first.
     */
    public function approve(SellerApplication $application): void
    {
        DB::transaction(function () use ($application) {
            $application->update([
                'status'      => 'approved',
                'plan'        => SubscriptionPlan::defaultPlan()->slug,
                'reviewed_at' => now(),
            ]);

            // Every approved seller has a subscription row (admin list, audit, limits)
            app(SubscriptionService::class)->getOrCreateSubscription($application);

            $application->user->update([
                'role'        => 'seller',
                'is_approved' => true,
                'is_active'   => true,
            ]);
        });

        $application->user->notify(
            new SellerApplicationReviewedNotification('approved', $application->business_name)
        );

        Mail::to($application->user)->queue(
            new SellerApplicationApprovedMail($application->user, $application)
        );
    }
}
