<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Translation\HasLocalePreference;
use App\Models\DeliveryCompanyProfile;
use App\Models\DeliveryGuyProfile;

class User extends Authenticatable implements HasLocalePreference
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'gender',
        'password',
        'has_password',
        'notification_preferences',
        'role',
        'is_active',
        'is_approved',
        'google_id',
        'avatar',
        'onboarding_completed',
        'locale',
        'marketing_emails_opt_in',
        'marketing_opt_in_at',
        'stock_alerts_enabled',
        'stock_alert_threshold',
        'stock_alert_channel',
        'whatsapp_number',
        'preferred_language',
        // email_verified_at is set only at creation time via verifyEmail()
        // or immediately for Google OAuth users. It is intentionally NOT
        // in fillable for bulk-assignment safety.
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'unsubscribe_token',
    ];

    protected $casts = [
        'email_verified_at'   => 'datetime',
        'stock_alerts_enabled'  => 'boolean',
        'stock_alert_threshold' => 'integer',
        'is_active'           => 'boolean',
        'is_approved'         => 'boolean',
        'onboarding_completed' => 'boolean',
        'has_password'         => 'boolean',
        'date_of_birth'        => 'date:Y-m-d',
        'notification_preferences' => 'array',
        'marketing_emails_opt_in' => 'boolean',
        'marketing_opt_in_at'     => 'datetime',
        'last_marketing_email_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // One-click unsubscribe links in marketing e-mails need a per-user secret.
        static::creating(function (User $user) {
            $user->unsubscribe_token ??= \Illuminate\Support\Str::random(40);
        });
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function sellerProfile()      { return $this->hasOne(SellerProfile::class); }
    public function products()           { return $this->hasMany(Product::class, 'seller_id'); }
    public function orders()             { return $this->hasMany(Order::class); }
    public function sellerApplication()  { return $this->hasOne(SellerApplication::class)->latest(); }
    public function sellerApplications() { return $this->hasMany(SellerApplication::class); }

    public function preferences()
    {
        return $this->hasOne(\App\Models\UserPreference::class);
    }

    public function deliveryAssignments()
    {
        return $this->hasMany(DeliveryAssignment::class, 'delivery_guy_id');
    }

    public function assignedOrders()
    {
        return $this->hasMany(DeliveryAssignment::class, 'assigned_by');
    }

    public function addresses()
    {
        return $this->hasMany(UserAddress::class)
                    ->orderByDesc('is_default')
                    ->orderByDesc('created_at');
    }

    public function defaultAddress()
    {
        return $this->hasOne(UserAddress::class)->where('is_default', true);
    }

    /** @var array{0: ?string}|null  memo for targetingWilaya() (a 1-tuple, so null results are cached too) */
    private ?array $targetingWilayaMemo = null;

    /**
     * Where this buyer is, for ad targeting: the default address's wilaya, else the
     * wilaya of their latest order. Canonical name (App\Support\Wilayas) or null.
     */
    public function targetingWilaya(): ?string
    {
        if ($this->targetingWilayaMemo === null) {
            $raw = $this->defaultAddress()->value('wilaya')
                ?? $this->orders()->whereNotNull('wilaya')->latest()->value('wilaya');
            $this->targetingWilayaMemo = [\App\Support\Wilayas::normalize($raw)];
        }
        return $this->targetingWilayaMemo[0];
    }

    // ── Role helpers ───────────────────────────────────────────────────────

    public function isAdmin()          { return $this->role === 'admin'; }
    public function isSeller()         { return $this->role === 'seller'; }
    public function isClient()         { return $this->role === 'client'; }
    public function isApprovedSeller() { return $this->isSeller() && $this->is_approved; }
    public function isActiveUser()     { return $this->is_active; }
    public function isEmailVerified()  { return (bool) $this->email_verified_at; }
    public function isDeliveryAdmin()  { return $this->role === 'delivery_admin'; }
    public function isDeliveryGuy()    { return $this->role === 'delivery_guy'; }

    /** Unset on a freshly created model = the column default (true). */
    public function getHasPasswordAttribute($value): bool
    {
        return $value === null ? true : (bool) $value;
    }

    /** Profile completion is only enforced on shoppers — see App\Support\ProfileCompletion. */
    public function profileCompletion(): \App\Support\ProfileCompletion
    {
        return new \App\Support\ProfileCompletion($this);
    }

    /**
     * Customer notification preference (missing keys default to on).
     * Keys: email_updates (complaint/refund e-mails), in_app_updates (bell).
     */
    public function wantsNotification(string $key): bool
    {
        return (bool) (($this->notification_preferences ?? [])[$key] ?? true);
    }

    public function needsOnboarding(): bool
    {
        return $this->role === 'client' && !$this->onboarding_completed;
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeActive($q)          { return $q->where('is_active', true); }
    public function scopeSellers($q)         { return $q->where('role', 'seller'); }
    public function scopeApprovedSellers($q) { return $q->where('role', 'seller')->where('is_approved', true); }
    public function scopePendingSellers($q)  { return $q->where('role', 'seller')->where('is_approved', false); }
    public function scopeClients($q)         { return $q->where('role', 'client'); }
    public function scopeAdmins($q)          { return $q->where('role', 'admin'); }
    public function scopeDeliveryGuys($q)    { return $q->where('role', 'delivery_guy'); }
    public function scopeVerified($q)        { return $q->whereNotNull('email_verified_at'); }

    public static function getAllAdmins()
    {
        return static::where('role', 'admin')
                     ->where('is_active', true)
                     ->get();
    }

    /**
     * Store branding for this seller — business_name, avatar and cover photo,
     * resolved from the latest approved SellerApplication with a fallback to
     * the plain account name/avatar. Single source of truth: every place that
     * shows this seller's storefront identity (product pages, seller page,
     * recommendations) must read it from here rather than re-deriving it.
     */
    public function storefrontBranding(): array
    {
        $application = SellerApplication::where('user_id', $this->id)
            ->approved()
            ->latest()
            ->first();

        return [
            'business_name' => $application?->business_name ?? $this->name,
            'avatar'        => $application?->profile_picture
                ? \Illuminate\Support\Facades\Storage::url($application->profile_picture)
                : $this->avatar,
            'cover_photo'   => $application?->cover_photo
                ? \Illuminate\Support\Facades\Storage::url($application->cover_photo)
                : null,
        ];
    }

    public function deliveryCompanyProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeliveryCompanyProfile::class);
    }

    public function deliveryGuyProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeliveryGuyProfile::class);
    }

    /**
     * Language used for this user's notifications and e-mails (saved from the storefront).
     */
    public function preferredLocale()
    {
        return in_array($this->locale, \App\Http\Middleware\SetLocale::SUPPORTED, true)
            ? $this->locale
            : \App\Http\Middleware\SetLocale::DEFAULT;
    }
}