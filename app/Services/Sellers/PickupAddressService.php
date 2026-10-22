<?php

namespace App\Services\Sellers;

use App\Jobs\Intigo\SendIntigoParcel;
use App\Models\IntigoZone;
use App\Models\SellerAddressChange;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\SellerPickupAddress;
use App\Models\User;
use App\Notifications\Sellers\SellerPickupChangedNotification;
use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\IntigoException;
use App\Services\Intigo\ParcelSender;
use App\Services\Intigo\ZoneGeocoder;
use App\Support\TunisianPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of seller_pickup_addresses.
 *
 *   save()            seller (application / settings) or admin: validated values,
 *                     history row (old → new), flags reset, seller_applications
 *                     mirror (wilaya, city, pickup_*) kept in sync, admin notified
 *                     when a seller changes it
 *   verify()          admin "Adresse vérifiée"
 *   createInIntigo()  admin "Créer dans Intigo" (verified first): POST
 *                     /pickup-addresses/, or PATCH the seller's own index when we
 *                     created it earlier; the index is saved on the seller
 *   markCreated()     admin "Pickup créé dans Intigo" (typed by hand in Intigo)
 *
 * Parcels go to Intigo only while the address is verified AND created
 * (ParcelPayload). Parcels held meanwhile are re-sent once it is created.
 */
class PickupAddressService
{
    /** ParcelPayload's problem while the address isn't validated at Intigo (also finds the held parcels). */
    public const HOLD = 'pickup address not validated at Intigo yet';

    /** Parcels the courier hasn't collected yet. */
    public const NOT_COLLECTED = ['pending', 'confirmed'];

    public function __construct(private IntigoClient $intigo) {}

    // ── Validation ─────────────────────────────────────────────────────────

    public static function prepare(Request $request): void
    {
        $merge = [];
        if ($request->has('phone')) {
            $merge['phone'] = TunisianPhone::normalize((string) $request->input('phone'));
        }
        foreach (['contact_name', 'adresse_complete', 'code_postal', 'indications'] as $key) {
            if (is_string($request->input($key))) {
                $merge[$key] = trim(preg_replace('/\s+/u', ' ', $request->input($key)));
            }
        }
        $request->merge($merge);
    }

    /** Same rules everywhere (application, settings, admin), mirrored by the Next.js component. */
    public static function rules(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes|required';
        $b = ZoneGeocoder::BOUNDS;

        return [
            'contact_name'     => "{$r}|string|min:3|max:191",
            'phone'            => [...explode('|', $r), 'string', 'regex:' . TunisianPhone::PATTERN],
            'adresse_complete' => "{$r}|string|min:5|max:200",
            'intigo_zone_id'   => [...explode('|', $r), 'integer', Rule::exists('intigo_zones', 'id')->where('active', true)],
            'code_postal'      => "{$r}|digits:4",
            // The pin, exactly as placed on the map: [latitude, longitude], inside Tunisia's outline
            // (a swapped pair or a point in Algeria / Libya / the open sea is refused)
            'latitude'         => [...explode('|', $r), 'numeric', "between:{$b['lat_min']},{$b['lat_max']}",
                function (string $attribute, mixed $value, \Closure $fail) {
                    $lng = request()->input('longitude');
                    if (is_numeric($value) && is_numeric($lng) && !ZoneGeocoder::inTunisia((float) $value, (float) $lng)) {
                        $fail(__('seller.pickup.pin_outside'));
                    }
                }],
            'longitude'        => "{$r}|numeric|between:{$b['lng_min']},{$b['lng_max']}",
            'indications'      => 'nullable|string|max:500',
        ];
    }

    public static function messages(): array
    {
        return [
            'intigo_zone_id.exists' => __('seller.pickup.zone_invalid'),
            'latitude.required'     => __('seller.pickup.pin_required'),
            'longitude.required'    => __('seller.pickup.pin_required'),
            'latitude.between'      => __('seller.pickup.pin_outside'),
            'longitude.between'     => __('seller.pickup.pin_outside'),
        ];
    }

    /** @return array<string, mixed> the validated address fields of a request */
    public static function validated(Request $request, bool $required = true): array
    {
        self::prepare($request);
        $data = $request->validate(self::rules($required), self::messages());
        return array_intersect_key($data, array_flip(SellerPickupAddress::FIELDS));
    }

    // ── Save ───────────────────────────────────────────────────────────────

    /**
     * @param string $source application | settings | admin
     * @return array{changed:bool, record:SellerPickupAddress, change:?SellerAddressChange, pending_pickups:int}
     */
    public function save(User $seller, array $values, ?User $actor, string $source): array
    {
        $result = DB::transaction(function () use ($seller, $values, $actor, $source) {
            $record = SellerPickupAddress::where('user_id', $seller->id)->lockForUpdate()->first()
                ?? new SellerPickupAddress(['user_id' => $seller->id]);

            $old = $record->exists ? $this->snapshot($record) : null;
            $record->fill(array_intersect_key($values, array_flip(SellerPickupAddress::FIELDS)));
            $zone = $record->intigo_zone_id ? IntigoZone::find($record->intigo_zone_id) : null;
            $record->gouvernorat = $zone?->gouvernorat;
            $new = $this->snapshot($record, $zone);

            $changed = $old === null ? array_keys(array_filter($new, fn ($v) => $v !== null)) : $this->diff($old, $new);
            $changed = array_values(array_intersect($changed, SellerPickupAddress::FIELDS));
            if ($record->exists && !$changed) {
                return ['changed' => false, 'record' => $record, 'change' => null, 'pending_pickups' => 0];
            }

            $pending = $this->pendingPickups($seller->id);
            $isAdmin = $source === 'admin';
            $wasAtIntigo = (bool) $record->intigo_pickup_created || (bool) $record->address_verified_by_admin;

            $record->forceFill([
                'needs_review'              => !$record->isLocated(),
                // Seller changes always need the admin again; an admin edit counts as checked
                'address_verified_by_admin' => $isAdmin && $record->isLocated(),
                'verified_at'               => $isAdmin && $record->isLocated() ? now() : null,
                'verified_by'               => $isAdmin && $record->isLocated() ? $actor?->id : null,
                'intigo_pickup_created'     => false,
                'intigo_pickup_created_at'  => null,
                // "Adresse modifiée – à mettre à jour": an existing seller's address changed
                'change_pending'            => $source !== 'application' && ($wasAtIntigo || $old !== null),
                'last_address_change_at'    => now(),
            ])->save();

            $change = SellerAddressChange::create([
                'user_id'         => $seller->id,
                'changed_by'      => $actor?->id,
                'changed_by_role' => $isAdmin ? 'admin' : 'seller',
                'source'          => $source,
                'old_values'      => $old,
                'new_values'      => $new,
                'changed_fields'  => $changed,
                'pending_pickups' => $pending,
            ]);

            $this->mirrorToApplication($seller->id, $record, $zone);

            return ['changed' => true, 'record' => $record, 'change' => $change, 'pending_pickups' => $pending];
        });

        if ($result['changed']) {
            Log::info("[Pickup] seller {$seller->id} address changed by " . ($actor?->id ?? 'system') . " ({$source}): " . implode(', ', $result['change']->changed_fields));
            if ($source === 'settings') {
                $this->notifyAdmins($seller, $result['change']);
            }
        }
        return $result;
    }

    /** Values stored in the history (zone label included so the diff reads like Intigo). */
    private function snapshot(SellerPickupAddress $r, ?IntigoZone $zone = null): array
    {
        $zone ??= $r->intigo_zone_id ? IntigoZone::find($r->intigo_zone_id) : null;
        return [
            'adresse_complete' => $r->adresse_complete,
            'intigo_zone_id'   => $r->intigo_zone_id !== null ? (int) $r->intigo_zone_id : null,
            'zone_label'       => $zone?->display_label,
            'zone_type'        => $zone?->type,
            'latitude'         => $r->latitude !== null ? round((float) $r->latitude, 7) : null,
            'longitude'        => $r->longitude !== null ? round((float) $r->longitude, 7) : null,
            'contact_name'     => $r->contact_name,
            'phone'            => $r->phone,
            'code_postal'      => $r->code_postal,
            'indications'      => $r->indications !== '' ? $r->indications : null,
        ];
    }

    private function diff(array $old, array $new): array
    {
        $out = [];
        foreach ($new as $key => $value) {
            $a = $old[$key] ?? null;
            if (in_array($key, ['latitude', 'longitude'], true)) {
                if (($a === null) !== ($value === null) || ($a !== null && abs($a - $value) > 0.0000005)) $out[] = $key;
            } elseif ((string) $a !== (string) $value) {
                $out[] = $key;
            }
        }
        return $out;
    }

    /** seller_applications still carries wilaya / city / pickup_* (shop pages, older readers). */
    private function mirrorToApplication(int $sellerId, SellerPickupAddress $r, ?IntigoZone $zone): void
    {
        $app = SellerApplication::where('user_id', $sellerId)->latest()->first();
        if (!$app) return;
        $app->forceFill(array_filter([
            'wilaya'             => $zone?->wilaya ?? $zone?->gouvernorat,
            'city'               => $zone?->delegation,
            'pickup_address'     => $r->adresse_complete,
            'pickup_postal_code' => $r->code_postal,
        ], fn ($v) => $v !== null) + ['pickup_notes' => $r->indications])->save();
    }

    public function pendingPickups(int $sellerId): int
    {
        return SellerOrder::where('seller_id', $sellerId)->whereIn('status', self::NOT_COLLECTED)->count();
    }

    private function notifyAdmins(User $seller, SellerAddressChange $change): void
    {
        try {
            Notification::send(User::getAllAdmins(), new SellerPickupChangedNotification($seller, $change));
        } catch (\Throwable $e) {
            // The change is saved and logged; the bell / mail must not undo it
            Log::error("[Pickup] admin notice for seller {$seller->id} failed: " . $e->getMessage());
        }
    }

    // ── Admin actions ──────────────────────────────────────────────────────

    public function verify(SellerPickupAddress $record, User $admin, bool $verified = true): SellerPickupAddress
    {
        if ($verified && ($missing = $this->missing($record))) {
            throw ValidationException::withMessages(['address' => 'Incomplete address: ' . implode(', ', $missing) . '.']);
        }
        $record->forceFill([
            'address_verified_by_admin' => $verified,
            'verified_at'               => $verified ? now() : null,
            'verified_by'               => $verified ? $admin->id : null,
            'needs_review'              => !$record->isLocated(),
        ])->save();
        Log::info("[Pickup] seller {$record->user_id} address " . ($verified ? 'verified' : 'un-verified') . " by admin {$admin->id}");
        return $record;
    }

    /**
     * "Créer dans Intigo". PATCH when the seller's index is one we created
     * through the API (Intigo keeps the index, so the switch is instant),
     * POST a new address otherwise. Fake mode: simulated, nothing created.
     *
     * @throws ValidationException|IntigoException
     */
    public function createInIntigo(User $seller, User $admin): SellerPickupAddress
    {
        $record = $this->requireVerified($seller);
        $body   = $this->intigoPickupBody($record);

        $ownIndex = $seller->intigo_pickup_index !== null
            && $record->intigo_pickup_created_via === 'api'
            && (string) $seller->intigo_pickup_index !== (string) config('intigo.platform_pickup_index');

        $result = $ownIndex
            ? $this->intigo->updatePickupAddress((int) $seller->intigo_pickup_index, $body)
            : $this->intigo->createPickupAddress($body + ['is_primary' => false]);

        $this->markCreated($seller, $record, $admin, $result['index'], $result['label'], 'api');
        Log::info("[Pickup] seller {$seller->id} " . ($ownIndex ? 'updated' : 'created') . " at Intigo ({$this->intigo->mode()}): index {$result['index']} by admin {$admin->id}"
            . " at {$body['latitude']},{$body['longitude']}");

        // Live: read it back (GET /pickup-addresses/) and tell the admins if Intigo holds something else
        $this->lastReadback = $this->intigo->mode() === 'live'
            ? $this->readBack($seller, (int) $result['index'], $body)
            : null;
        return $record->fresh();
    }

    /** Problems found by the last read-back (null = not checked, [] = Intigo matches). */
    public ?array $lastReadback = null;

    /**
     * POST/PATCH /pickup-addresses body, field names and order of the official spec
     * (PickupAddressCreateRequest: street_address, city_id, district_id, neighborhood_id, latitude,
     * longitude; is_primary added on creation). latitude / longitude = the seller's pin, exactly as
     * saved from the map (7 decimals): never a zone centre, never geocoded from the text.
     */
    public function intigoPickupBody(SellerPickupAddress $record): array
    {
        $zone = $record->zone;
        return [
            'street_address'  => $this->intigoStreetAddress($record),
            'city_id'         => (int) $zone->gouvernorat_id,
            'district_id'     => (int) $zone->delegation_id,
            'neighborhood_id' => $zone->isSecteur() ? (int) $zone->intigo_id : null,
            'latitude'        => round((float) $record->latitude, 7),
            'longitude'       => round((float) $record->longitude, 7),
        ];
    }

    /**
     * Compare what Intigo now holds with what we sent. Intigo's API never returns the coordinates
     * (PickupAddressItem: index, street_address, city_name, district_name, has_gps): the point is
     * checked as "has GPS"; the exact position can only be seen in the Intigo dashboard.
     *
     * @return string[] problems (empty = matches)
     */
    private function readBack(User $seller, int $index, array $sent): array
    {
        $zone = SellerPickupAddress::where('user_id', $seller->id)->first()?->zone;
        try {
            $item = collect($this->intigo->pickupAddresses())->firstWhere('index', $index);
        } catch (\Throwable $e) {
            $problems = ['could not read the pickup back from Intigo: ' . mb_substr($e->getMessage(), 0, 160)];
            $this->alertPickup($seller, $index, $problems);
            return $problems;
        }

        $fold = fn (?string $s) => \App\Services\Intigo\IntigoZones::fold((string) $s);
        $raw  = $item['raw'] ?? [];
        $problems = [];
        if (!$item) {
            $problems[] = "index {$index} is not in Intigo's pickup list";
        } else {
            if (array_key_exists('has_gps', $raw) && $raw['has_gps'] !== true) {
                $problems[] = 'Intigo has no GPS point for it (has_gps false): it will place it from the text';
            }
            if (isset($raw['street_address']) && trim((string) $raw['street_address']) !== trim($sent['street_address'])) {
                $problems[] = "address at Intigo \"{$raw['street_address']}\" differs from the one sent";
            }
            if ($zone && isset($raw['district_name']) && $fold($raw['district_name']) !== $fold($zone->delegation)) {
                $problems[] = "délégation at Intigo \"{$raw['district_name']}\" ≠ ours \"{$zone->delegation}\"";
            }
            if ($zone && isset($raw['city_name']) && $fold($raw['city_name']) !== $fold($zone->gouvernorat)) {
                $problems[] = "governorate at Intigo \"{$raw['city_name']}\" ≠ ours \"{$zone->gouvernorat}\"";
            }
        }
        if ($problems) {
            $this->alertPickup($seller, $index, $problems);
        }
        Log::info("[Pickup] seller {$seller->id} read back index {$index}: " . ($problems ? implode('; ', $problems) : 'matches'));
        return $problems;
    }

    /** Bell + e-mail (config intigo.alert_email) for a pickup that does not match at Intigo. */
    private function alertPickup(User $seller, int $index, array $problems): void
    {
        $title  = 'Intigo pickup differs from ours';
        $reason = "Seller #{$seller->id} {$seller->name}, Intigo pickup index {$index}: " . implode('; ', $problems)
            . '. Check the point in the Intigo dashboard (the API does not return coordinates).';
        try {
            Notification::send(User::getAllAdmins(), new \App\Notifications\Delivery\IntigoAdminNotification($title, $reason, null, null, 'pickup_mismatch'));
            $to = config('intigo.alert_email');
            if (is_string($to) && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                \Illuminate\Support\Facades\Mail::to($to)->queue(new \App\Mail\IntigoAlertMail($title, $reason, null, null, null, "vendeur #{$seller->id} (pickup {$index})"));
            }
        } catch (\Throwable $e) {
            Log::warning('[Pickup] read-back alert failed: ' . $e->getMessage());
        }
    }

    /** "Pickup créé dans Intigo": the admin typed it in the Intigo dashboard and gives its index. */
    public function markCreatedManually(User $seller, User $admin, int $index, ?string $label): SellerPickupAddress
    {
        $record = $this->requireVerified($seller);
        $this->markCreated($seller, $record, $admin, $index, $label, 'manual');
        return $record->fresh();
    }

    private function markCreated(User $seller, SellerPickupAddress $record, User $admin, int $index, ?string $label, string $via): void
    {
        DB::transaction(function () use ($seller, $record, $index, $label, $via) {
            $seller->forceFill(['intigo_pickup_index' => $index, 'intigo_pickup_label' => $label ? mb_substr($label, 0, 500) : null])->save();
            $record->forceFill([
                'intigo_pickup_created'     => true,
                'intigo_pickup_created_at'  => now(),
                'intigo_pickup_created_via' => $via,
                'change_pending'            => false,
                'needs_review'              => false,
            ])->save();
        });
        Log::info("[Pickup] seller {$seller->id} pickup index → {$index} ({$via}) by admin {$admin->id}");
        $this->releaseHeldParcels($seller->id);
    }

    private function requireVerified(User $seller): SellerPickupAddress
    {
        $record = SellerPickupAddress::with('zone')->where('user_id', $seller->id)->first();
        if (!$record || !$record->isLocated()) {
            throw ValidationException::withMessages(['address' => 'The seller has no complete pickup address (zone + map pin).']);
        }
        if (!$record->address_verified_by_admin) {
            throw ValidationException::withMessages(['address' => 'Mark the address as verified first.']);
        }
        return $record;
    }

    /** Parcels refused by ParcelPayload while the address wasn't ready: send them now. */
    public function releaseHeldParcels(int $sellerId): int
    {
        $ids = SellerOrder::where('seller_id', $sellerId)->where('status', 'confirmed')->whereNull('intigo_nid')
            ->where('intigo_sync_status', ParcelSender::FAILED)->where('intigo_error', 'like', '%' . self::HOLD . '%')
            ->pluck('id');
        foreach ($ids as $id) {
            SendIntigoParcel::dispatchSafely((int) $id, true);
        }
        if ($ids->isNotEmpty()) {
            Log::info("[Pickup] seller {$sellerId}: " . $ids->count() . ' held parcel(s) re-sent to Intigo');
        }
        return $ids->count();
    }

    // ── Presentation ───────────────────────────────────────────────────────

    /** Intigo's street_address: the address, then who to ask for (Intigo has no contact field). */
    public function intigoStreetAddress(SellerPickupAddress $r): string
    {
        $max    = 255;   // Intigo street_address maxLength
        $street = trim((string) $r->adresse_complete);

        // Google Maps link of the exact pin: the driver finds the shop even if Intigo's map
        // shows the point elsewhere (incident 2026-10-22: pin shown near Gabès for a Sfax shop)
        $map = config('intigo.pickup_map_link_in_address', true) ? self::mapLink($r->latitude, $r->longitude) : null;

        $tail = [];
        if (config('intigo.pickup_contact_in_address', true)) {
            $phone   = $r->phone ? TunisianPhone::format($r->phone) : null;
            $contact = trim(implode(' ', array_filter([$r->contact_name, $phone])));
            if ($contact !== '') {
                $tail['contact'] = "Contact : {$contact}";
            }
        }
        if ($map) {
            $tail['map'] = "Carte : {$map}";
        }

        $join = fn (string $s, array $t) => implode(' — ', array_filter([$s, ...array_values($t)], fn ($x) => $x !== ''));
        $full = $join($street, $tail);
        if (mb_strlen($full) > $max && isset($tail['contact'], $phone) && $phone) {
            $tail['contact'] = "Tél : {$phone}";       // too long: drop the name, keep the phone
            $full = $join($street, $tail);
        }
        if (mb_strlen($full) > $max) {
            // Still too long: shorten the street text, never the phone or the map link
            $room   = $max - mb_strlen($join('', $tail)) - mb_strlen(' — ') - 1;
            $street = $room > 0 ? rtrim(mb_substr($street, 0, $room)) . '…' : '';
            $full   = $join($street, $tail);
        }
        return mb_substr($full, 0, $max);
    }

    /** https://maps.google.com/?q=lat,lng of the pin as saved (7 decimals, trailing zeros dropped), null without a pin. */
    public static function mapLink(mixed $lat, mixed $lng): ?string
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }
        $fmt = fn ($v) => rtrim(rtrim(number_format(round((float) $v, 7), 7, '.', ''), '0'), '.');
        return 'https://maps.google.com/?q=' . $fmt($lat) . ',' . $fmt($lng);
    }

    /** @return string[] labels of what Intigo / the courier still needs */
    public function missing(?SellerPickupAddress $r): array
    {
        if (!$r) return ['address'];
        $missing = [];
        if (trim((string) $r->adresse_complete) === '') $missing[] = 'adresse complète';
        if (!$r->intigo_zone_id) $missing[] = 'zone';
        if ($r->latitude === null || $r->longitude === null) $missing[] = 'map pin';
        if (trim((string) $r->contact_name) === '') $missing[] = 'contact';
        if (!$r->phone || !TunisianPhone::isValid($r->phone)) $missing[] = 'phone';
        if (!preg_match('/^\d{4}$/', (string) $r->code_postal)) $missing[] = 'postal code';
        return $missing;
    }

    public function present(?SellerPickupAddress $r, bool $admin = false): array
    {
        if (!$r) {
            return ['exists' => false, 'status' => SellerPickupAddress::STATUS_NEEDS_REVIEW, 'missing' => $this->missing(null), 'complete' => false];
        }
        $zone = $r->relationLoaded('zone') ? $r->zone : $r->zone()->first();
        $out  = [
            'exists'                    => true,
            'adresse_complete'          => $r->adresse_complete,
            'intigo_zone_id'            => $r->intigo_zone_id,
            'zone'                      => $zone?->toOption(),
            'gouvernorat'               => $r->gouvernorat,
            'latitude'                  => $r->latitude,
            'longitude'                 => $r->longitude,
            'contact_name'              => $r->contact_name,
            'phone'                     => $r->phone,
            'code_postal'               => $r->code_postal,
            'indications'               => $r->indications,
            'status'                    => $r->status(),
            'needs_review'              => (bool) $r->needs_review,
            'change_pending'            => (bool) $r->change_pending,
            'address_verified_by_admin' => (bool) $r->address_verified_by_admin,
            'verified_at'               => $r->verified_at?->toIso8601String(),
            'intigo_pickup_created'     => (bool) $r->intigo_pickup_created,
            'intigo_pickup_created_at'  => $r->intigo_pickup_created_at?->toIso8601String(),
            'last_address_change_at'    => $r->last_address_change_at?->toIso8601String(),
            'intigo_ready'              => $r->isIntigoReady(),
            'missing'                   => $missing = $this->missing($r),
            'complete'                  => $missing === [],
            'pin_check'                 => ZoneGeocoder::check($zone, $r->latitude, $r->longitude),
        ];
        if ($admin) {
            $seller = $r->relationLoaded('user') ? $r->user : User::find($r->user_id, ['id', 'intigo_pickup_index', 'intigo_pickup_label']);
            $out += [
                'legacy_snapshot'           => $r->legacy_snapshot,
                'verified_by'               => $r->verified_by ? User::whereKey($r->verified_by)->value('name') : null,
                'intigo_pickup_created_via' => $r->intigo_pickup_created_via,
                'intigo_pickup_index'       => $seller?->intigo_pickup_index,
                'intigo_pickup_label'       => $seller?->intigo_pickup_label,
                'intigo_street_address'     => $r->adresse_complete ? $this->intigoStreetAddress($r) : null,
                'google_maps_url'           => $r->latitude !== null ? "https://www.google.com/maps/search/?api=1&query={$r->latitude},{$r->longitude}" : null,
                'pending_pickups'           => $this->pendingPickups($r->user_id),
                // Exactly what "Créer / Mettre à jour dans Intigo" sends (preview before the click)
                'intigo_payload'            => $zone && $r->adresse_complete && $r->latitude !== null && $r->longitude !== null
                    ? $this->intigoPickupBody($r) : null,
                'in_tunisia'                => ZoneGeocoder::inTunisia($r->latitude !== null ? (float) $r->latitude : null, $r->longitude !== null ? (float) $r->longitude : null),
            ];
        }
        return $out;
    }

    /** History rows for the admin timeline. */
    public function history(int $sellerId, int $limit = 50): array
    {
        return SellerAddressChange::with('author:id,name')->where('user_id', $sellerId)->orderByDesc('id')->limit($limit)->get()
            ->map(fn (SellerAddressChange $c) => [
                'id'              => $c->id,
                'at'              => $c->created_at?->toIso8601String(),
                'by'              => $c->author?->name,
                'by_role'         => $c->changed_by_role,
                'source'          => $c->source,
                'changed_fields'  => $c->changed_fields,
                'old'             => $c->old_values,
                'new'             => $c->new_values,
                'pending_pickups' => $c->pending_pickups,
            ])->all();
    }
}
