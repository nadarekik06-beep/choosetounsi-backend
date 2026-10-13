<?php

namespace App\Services\Intigo;

use Illuminate\Support\Facades\DB;

/**
 * Fake mode (config('intigo.mode') = fake, the default): realistic answers,
 * no network call, no real parcel. Every call is logged in intigo_logs with
 * mode "fake", exactly like live calls.
 *
 *   createParcel  validates like Intigo (400 on a missing field, unknown city,
 *                 short phone; 402 on price 0) and returns nid "FAKE-{id}"
 *   findByCid     the parcel already saved on that seller_order, if any
 *   statuses      the last status we stored (move it with intigo:simulate-webhook)
 *   cancel        allowed during the pickup phase only (409 afterwards)
 *   pickup addresses  create / update validated like Intigo (ids must belong
 *                 together, lat+lng both or neither) and kept in
 *                 storage/app/intigo/fake-pickup-addresses.json: nothing reaches Intigo
 */
class FakeIntigoClient implements IntigoClient
{
    public function __construct(private IntigoLogger $logger) {}

    public function mode(): string
    {
        return 'fake';
    }

    public function createParcel(array $payload, ?int $sellerOrderId = null): array
    {
        $errors = [];
        foreach (['recipient_name', 'phone1', 'destination_address', 'city_name', 'district_name'] as $field) {
            if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                $errors[] = ['loc' => ['body', $field], 'msg' => 'field required', 'type' => 'value_error.missing'];
            }
        }
        if (isset($payload['phone1']) && strlen(preg_replace('/\D/', '', (string) $payload['phone1'])) < 8) {
            $errors[] = ['loc' => ['body', 'phone1'], 'msg' => 'at least 8 digits', 'type' => 'value_error'];
        }
        if (!isset($payload['pickup_index']) || !is_int($payload['pickup_index'])) {
            $errors[] = ['loc' => ['body', 'pickup_index'], 'msg' => 'field required', 'type' => 'value_error.missing'];
        }
        if (!in_array($payload['package_size'] ?? null, [1, 4, 5], true)) {
            $errors[] = ['loc' => ['body', 'package_size'], 'msg' => 'must be 1, 4 or 5', 'type' => 'value_error'];
        }
        foreach (['description', 'additional_info'] as $field) {
            if (mb_strlen((string) ($payload[$field] ?? '')) > 500) {
                $errors[] = ['loc' => ['body', $field], 'msg' => 'max 500 characters', 'type' => 'value_error'];
            }
        }
        if (!$errors && !empty($payload['city_name']) && !$this->knownCity((string) $payload['city_name'])) {
            return $this->fail('POST', '/parcels/by-name', $payload, 400, ['detail' => 'Ville inconnue'], $sellerOrderId);
        }
        if ($errors) {
            return $this->fail('POST', '/parcels/by-name', $payload, 400, ['detail' => $errors], $sellerOrderId);
        }
        if ((float) ($payload['price'] ?? 0) <= 0) {
            return $this->fail('POST', '/parcels/by-name', $payload, 402, ['detail' => 'Solde insuffisant pour un colis prépayé'], $sellerOrderId);
        }

        $nid = $this->newNid($sellerOrderId);
        [$district, $fallback] = $this->district((string) $payload['city_name'], (string) $payload['district_name']);
        $body = ['success' => true, 'nid' => $nid, 'district_name' => $district, 'district_fallback' => $fallback];
        $this->logger->log('fake', 'POST', '/parcels/by-name', $payload, 201, $body, 0, $sellerOrderId);

        return ['nid' => $nid, 'district_name' => $district, 'district_fallback' => $fallback, 'raw' => $body];
    }

    public function findByCid(string $cid, ?int $sellerOrderId = null): array
    {
        $found = [];
        if (preg_match('/^CT-SO-(\d+)$/', $cid, $m)) {
            $so = DB::table('seller_orders')->where('id', (int) $m[1])->first(['intigo_nid', 'intigo_status_code', 'intigo_status_label']);
            if ($so && $so->intigo_nid) {
                $found[] = ['nid' => $so->intigo_nid, 'cid' => $cid, 'status' => $so->intigo_status_code ?? 1000, 'status_label' => $so->intigo_status_label];
            }
        }
        $this->logger->log('fake', 'GET', '/parcels/?cid=' . $cid, null, 200, ['parcels' => $found], 0, $sellerOrderId);
        return $found;
    }

    public function getParcel(string $nid, ?int $sellerOrderId = null): array
    {
        $so = $this->parcelRow($nid);
        if (!$so) {
            return $this->fail('GET', "/parcels/{$nid}", null, 404, ['detail' => 'Colis introuvable'], $sellerOrderId);
        }
        $body = ['nid' => $nid, 'cid' => 'CT-SO-' . $so->id, 'status' => $so->intigo_status_code ?? 1000, 'status_label' => $so->intigo_status_label ?? 'En attente de ramassage'];
        $this->logger->log('fake', 'GET', "/parcels/{$nid}", null, 200, $body, 0, $sellerOrderId);
        return $body;
    }

    public function history(string $nid, ?int $sellerOrderId = null): array
    {
        $rows = DB::table('intigo_webhook_events')->where('nid', $nid)->orderBy('id')
            ->get(['new_status as status', 'status_label', 'event_timestamp as date'])->map(fn ($r) => (array) $r)->all();
        $body = ['nid' => $nid, 'history' => $rows];
        $this->logger->log('fake', 'GET', "/parcels/{$nid}/history", null, 200, $body, 0, $sellerOrderId);
        return $body;
    }

    public function statuses(array $nids): array
    {
        $out = [];
        foreach (DB::table('seller_orders')->whereIn('intigo_nid', $nids)->get(['intigo_nid', 'intigo_status_code', 'intigo_status_label']) as $so) {
            $out[$so->intigo_nid] = ['status' => (int) ($so->intigo_status_code ?? 1000), 'status_label' => $so->intigo_status_label ?? 'En attente de ramassage'];
        }
        $this->logger->log('fake', 'POST', '/parcels/status', ['nids' => array_values($nids)], 200, ['parcels' => $out], 0, null);
        return $out;
    }

    public function cancel(string $nid, ?int $sellerOrderId = null): array
    {
        $so = $this->parcelRow($nid);
        if (!$so) {
            return $this->fail('POST', "/parcels/{$nid}/cancel", [], 404, ['detail' => 'Colis introuvable'], $sellerOrderId);
        }
        if (!IntigoStatusMap::isPickupPhase($so->intigo_status_code)) {
            return $this->fail('POST', "/parcels/{$nid}/cancel", [], 409, ['detail' => 'Annulation impossible : le colis a déjà été ramassé'], $sellerOrderId);
        }
        $body = ['success' => true, 'nid' => $nid];
        $this->logger->log('fake', 'POST', "/parcels/{$nid}/cancel", [], 200, $body, 0, $sellerOrderId);
        return $body;
    }

    public function pickupAddresses(): array
    {
        $body = ['success' => true, 'pickup_addresses' => array_values(array_merge([
            ['index' => 0, 'name' => "CHOOSE'Tounsi", 'address' => 'Entrepôt Choose\'Tounsi, Rue de l\'Industrie', 'city_name' => 'Ben Arous', 'district_name' => 'Megrine'],
            ['index' => 1, 'name' => 'Boutique Démo — 22 123 456', 'address' => '12 Avenue Habib Bourguiba', 'city_name' => 'Sousse', 'district_name' => 'Sousse Ville'],
            ['index' => 2, 'name' => 'Atelier Démo — 98 765 432', 'address' => 'Route de Gremda km 4', 'city_name' => 'Sfax', 'district_name' => 'Sfax Ville'],
        ], $this->storedPickups()))];
        $this->logger->log('fake', 'GET', '/pickup-addresses/', null, 200, $body, 0, null);
        return Shapes::pickupAddresses($body);
    }

    public function createPickupAddress(array $body): array
    {
        $this->validatePickup('POST', '/pickup-addresses/', $body, true);
        $stored = $this->storedPickups();
        $index  = max([2, ...array_map(fn ($p) => (int) $p['index'], $stored)]) + 1;
        $item   = $this->pickupItem($index, $body);
        $stored[] = $item;
        $this->storePickups($stored);
        $answer = ['success' => true, 'pickup_address' => $item];
        $this->logger->log('fake', 'POST', '/pickup-addresses/', $body, 201, $answer, 0, null);
        return Shapes::pickupAddress($answer);
    }

    public function updatePickupAddress(int $index, array $body): array
    {
        $stored = $this->storedPickups();
        $key    = array_search($index, array_map(fn ($p) => (int) $p['index'], $stored), true);
        if ($key === false) {
            $this->fail('PATCH', "/pickup-addresses/{$index}", $body, 404, ['detail' => 'Adresse de ramassage introuvable'], null);
        }
        $merged = $body + ($stored[$key]['_body'] ?? []);
        $this->validatePickup('PATCH', "/pickup-addresses/{$index}", $merged, false);
        $stored[$key] = $this->pickupItem($index, $merged);
        $this->storePickups($stored);
        $answer = ['success' => true, 'pickup_address' => $stored[$key]];
        $this->logger->log('fake', 'PATCH', "/pickup-addresses/{$index}", $body, 200, $answer, 0, null);
        return Shapes::pickupAddress($answer);
    }

    /** Like Intigo: street 1..255, city required, district in the city, quartier in the district, lat+lng together. */
    private function validatePickup(string $method, string $path, array $body, bool $create): void
    {
        $errors = [];
        $street = trim((string) ($body['street_address'] ?? ''));
        if ($street === '' || mb_strlen($street) > 255) {
            $errors[] = ['loc' => ['body', 'street_address'], 'msg' => '1..255 characters', 'type' => 'value_error'];
        }
        if ($create && empty($body['city_id'])) {
            $errors[] = ['loc' => ['body', 'city_id'], 'msg' => 'field required', 'type' => 'value_error.missing'];
        }
        if (isset($body['latitude']) !== isset($body['longitude'])) {
            $errors[] = ['loc' => ['body', 'latitude'], 'msg' => 'supply latitude with longitude or neither', 'type' => 'value_error'];
        }
        if ($errors) {
            $this->fail($method, $path, $body, 422, ['detail' => $errors], null);
        }
        if (!DB::table('intigo_cities')->exists()) {
            return;
        }
        if (!DB::table('intigo_cities')->where('id', $body['city_id'] ?? 0)->exists()) {
            $this->fail($method, $path, $body, 400, ['detail' => 'Ville inconnue'], null);
        }
        if (!empty($body['district_id']) && !DB::table('intigo_districts')->where('id', $body['district_id'])->where('city_id', $body['city_id'])->exists()) {
            $this->fail($method, $path, $body, 400, ['detail' => "La délégation n'appartient pas à cette ville"], null);
        }
        if (!empty($body['neighborhood_id']) && DB::table('intigo_neighborhoods')->exists()
            && !DB::table('intigo_neighborhoods')->where('id', $body['neighborhood_id'])->where('district_id', $body['district_id'] ?? 0)->exists()) {
            $this->fail($method, $path, $body, 400, ['detail' => "Le quartier n'appartient pas à cette délégation"], null);
        }
    }

    private function pickupItem(int $index, array $body): array
    {
        return [
            'id'             => 9000 + $index,
            'index'          => $index,
            'street_address' => $body['street_address'],
            'city_name'      => DB::table('intigo_cities')->where('id', $body['city_id'] ?? 0)->value('name'),
            'district_name'  => DB::table('intigo_districts')->where('id', $body['district_id'] ?? 0)->value('name'),
            'has_gps'        => isset($body['latitude'], $body['longitude']),
            '_body'          => array_intersect_key($body, array_flip(['street_address', 'city_id', 'district_id', 'neighborhood_id', 'latitude', 'longitude', 'is_primary'])),
        ];
    }

    private function storedPickups(): array
    {
        $path = storage_path('app/intigo/fake-pickup-addresses.json');
        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    private function storePickups(array $items): void
    {
        $path = storage_path('app/intigo/fake-pickup-addresses.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private function fail(string $method, string $path, mixed $request, int $status, array $body, ?int $sellerOrderId): never
    {
        $e = IntigoException::fromResponse($status, $body);
        $this->logger->log('fake', $method, $path, $request, $status, $body, 0, $sellerOrderId, $e->getMessage());
        throw $e;
    }

    /** "FAKE-{id}", then "FAKE-{id}-2"… when an earlier fake parcel of that seller_order was cancelled. */
    private function newNid(?int $sellerOrderId): string
    {
        $base = 'FAKE-' . ($sellerOrderId ?? strtoupper(bin2hex(random_bytes(3))));
        $nid  = $base;
        for ($n = 2; DB::table('seller_orders')->where('intigo_nid', $nid)->exists()
            || DB::table('intigo_webhook_events')->where('nid', $nid)->exists()
            || DB::table('intigo_logs')->where('endpoint', '/parcels/by-name')->where('http_status', 201)->where('response', 'like', '%"' . $nid . '"%')->exists(); $n++) {
            $nid = "{$base}-{$n}";
        }
        return $nid;
    }

    private function parcelRow(string $nid): ?object
    {
        return DB::table('seller_orders')->where('intigo_nid', $nid)->first(['id', 'intigo_status_code', 'intigo_status_label']);
    }

    /** Like Intigo: unknown city → 400 (only checked once the regions are synced). */
    private function knownCity(string $name): bool
    {
        if (!DB::table('intigo_cities')->exists()) {
            return true;
        }
        $key = mb_strtolower(trim($name));
        return DB::table('intigo_cities')->get(['name', 'name_ar', 'wilaya'])
            ->contains(fn ($c) => in_array($key, array_map(fn ($v) => mb_strtolower((string) $v), [$c->name, $c->name_ar, $c->wilaya]), true));
    }

    /** Like Intigo: an unknown district falls back to the governorate's main town. */
    private function district(string $city, string $district): array
    {
        if (!DB::table('intigo_districts')->exists()) {
            return [$district, false];
        }
        $known = DB::table('intigo_districts')->whereRaw('LOWER(name) = ?', [mb_strtolower($district)])->orWhere('name_ar', $district)->value('name');
        if ($known) {
            return [$known, false];
        }
        $cityId = DB::table('intigo_cities')->whereRaw('LOWER(name) = ?', [mb_strtolower($city)])->orWhere('name_ar', $city)->orWhere('wilaya', $city)->value('id');
        $guess  = $cityId ? DB::table('intigo_districts')->where('city_id', $cityId)->orderBy('id')->value('name') : null;
        return [$guess ?? $city, true];
    }
}
