<?php

namespace Tests\Feature\Intigo;

use App\Mail\IntigoAlertMail;
use App\Notifications\Orders\SellerBordereauReadyNotification;
use App\Services\Intigo\BordereauClient;
use App\Services\Intigo\IntigoException;
use App\Services\Orders\CheckoutGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sellers print their Intigo bordereau (fake mode: a local test PDF, no network).
 * (Intigo API source, INTIGO_BORDEREAU_ENABLED=true.) Owner OK, other seller 403, no parcel 404, counters + audit log, admins told
 * once, admin download not counted, Intigo errors are friendly, bulk merge.
 */
class BordereauDownloadTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        config(['intigo.alert_email' => 'alerts@example.com', 'intigo.bordereau.enabled' => true]);
        Mail::fake();
        Storage::fake('local');
    }

    private function confirmedParcel($seller)
    {
        $product = $this->product($seller, 40, false, 10);
        $this->addToCart($product, 1);
        [, $orderId] = $this->placeAndConfirm();
        return $this->parcel($orderId, $seller);
    }

    private function adminNotes(string $type): int
    {
        return DB::table('notifications')->where('notifiable_id', $this->admin->id)
            ->where('data', 'like', '%"type":"' . $type . '"%')->count();
    }

    public function test_owner_downloads_the_fake_pdf_and_counters_and_log_are_updated(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);

        $res = $this->as($seller)->get("/api/seller/orders/{$so->id}/bordereau");
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $number = DB::table('orders')->where('id', $so->order_id)->value('order_number');
        $this->assertStringContainsString('bordereau-' . $number . '.pdf', $res->headers->get('Content-Disposition'));

        $fresh = $this->so($so);
        $this->assertSame(1, $fresh->bordereau_download_count);
        $this->assertNotNull($fresh->bordereau_first_downloaded_at);
        $this->assertNotNull($fresh->bordereau_last_downloaded_at);
        Storage::disk('local')->assertExists($fresh->bordereau_path);

        $log = DB::table('bordereau_downloads')->where('seller_order_id', $so->id)->first();
        $this->assertSame($seller->id, (int) $log->user_id);
        $this->assertSame($seller->id, (int) $log->seller_id);
        $this->assertSame('seller', $log->source);

        // The seller's order list shows it (added field only)
        $item = collect($this->as($seller)->getJson('/api/seller/orders')->json('data.data'))->firstWhere('id', $so->id);
        $this->assertTrue($item['delivery']['bordereau']['available']);
        $this->assertTrue($item['delivery']['bordereau']['enabled']);
        $this->assertSame(1, $item['delivery']['bordereau']['print_count']);
    }

    public function test_second_download_uses_the_cache_and_admins_are_told_once(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);

        $this->as($seller)->get("/api/seller/orders/{$so->id}/bordereau")->assertOk();
        $this->as($seller)->get("/api/seller/orders/{$so->id}/bordereau")->assertOk();
        $this->as($seller)->get("/api/seller/orders/{$so->id}/bordereau")->assertOk();

        $this->assertSame(3, $this->so($so)->bordereau_download_count);
        $this->assertSame(3, DB::table('bordereau_downloads')->where('seller_order_id', $so->id)->count());
        $this->assertSame(1, DB::table('intigo_logs')->where('seller_order_id', $so->id)->where('endpoint', 'like', '/secure-api/bordereaux/%')->count(), 'fetched once, then cached');

        $this->assertSame(1, $this->adminNotes('intigo_bordereau_printed'), 'bell once');
        Mail::assertQueued(IntigoAlertMail::class, 2);   // "prepared" + "bordereau printed", nothing more
    }

    public function test_another_seller_gets_403(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);

        $other = $this->sellerWithPickup(4);
        $this->as($other)->get("/api/seller/orders/{$so->id}/bordereau")->assertStatus(403);
        $this->assertSame(0, DB::table('bordereau_downloads')->count());
        $this->assertSame(0, $this->so($so)->bordereau_download_count);
    }

    public function test_no_parcel_yet_gets_404(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);

        $this->as($seller)->getJson("/api/seller/orders/{$so->id}/bordereau")
            ->assertStatus(404)->assertJson(['code' => 'not_available']);
        $this->assertSame(0, DB::table('bordereau_downloads')->count());
    }

    public function test_intigo_error_is_a_friendly_502_without_details(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);

        $this->app->instance(BordereauClient::class, new class implements BordereauClient {
            public function mode(): string { return 'live'; }
            public function pdf(string $nid, ?int $sellerOrderId = null): string
            {
                throw IntigoException::fromResponse(500, ['detail' => 'internal secret trace']);
            }
        });

        $res = $this->as($seller)->getJson("/api/seller/orders/{$so->id}/bordereau");
        $res->assertStatus(502)->assertJson(['code' => 'intigo_error']);
        $this->assertStringNotContainsString('secret trace', $res->getContent());
        $this->assertSame(0, $this->so($so)->bordereau_download_count, 'a failed print is not counted');
        $this->assertSame(0, $this->adminNotes('intigo_bordereau_printed'));
    }

    public function test_intigo_timeout_is_a_friendly_504(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);

        $this->app->instance(BordereauClient::class, new class implements BordereauClient {
            public function mode(): string { return 'live'; }
            public function pdf(string $nid, ?int $sellerOrderId = null): string
            {
                throw IntigoException::connection('cURL error 28: timed out');
            }
        });

        $this->as($seller)->getJson("/api/seller/orders/{$so->id}/bordereau")
            ->assertStatus(504)->assertJson(['code' => 'intigo_timeout']);
    }

    public function test_admin_download_is_logged_but_not_counted_or_notified(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);

        $res = $this->as($this->admin)->get("/api/admin/seller-orders/{$so->id}/bordereau");
        $res->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());

        $this->assertSame(0, $this->so($so)->bordereau_download_count);
        $this->assertNull($this->so($so)->bordereau_first_downloaded_at);
        $this->assertSame('admin', DB::table('bordereau_downloads')->where('seller_order_id', $so->id)->value('source'));
        $this->assertSame(0, $this->adminNotes('intigo_bordereau_printed'));

        // The seller's own first print still notifies
        $this->as($seller)->get("/api/seller/orders/{$so->id}/bordereau")->assertOk();
        $this->assertSame(1, $this->adminNotes('intigo_bordereau_printed'));
    }

    public function test_admin_list_badge_and_not_printed_filter(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);

        $ids = fn () => collect($this->as($this->admin)->getJson('/api/admin/orders?bordereau=not_printed&per_page=100')->json('data.data'))->pluck('id')->all();
        $this->assertContains($so->order_id, $ids());

        $this->as($seller)->get("/api/seller/orders/{$so->id}/bordereau")->assertOk();
        $this->assertNotContains($so->order_id, $ids());

        $order = collect($this->as($this->admin)->getJson('/api/admin/orders?per_page=100')->json('data.data'))->firstWhere('id', $so->order_id);
        $badge = collect($order['parcels_summary'])->firstWhere('id', $so->id)['bordereau'];
        $this->assertSame(1, $badge['print_count']);
        $this->assertNotNull($badge['first_printed_at']);
    }

    public function test_seller_is_told_the_bordereau_is_ready_once_the_parcel_exists(): void
    {
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->assertSame(0, DB::table('seller_order_notifications')->where('seller_order_id', $so->id)->where('event', 'bordereau_ready')->count());

        $this->prepare($so, $seller);
        $this->prepare($so, $seller);   // double click

        $this->assertSame(1, DB::table('seller_order_notifications')->where('seller_order_id', $so->id)->where('event', 'bordereau_ready')->count());
        $note = DB::table('notifications')->where('notifiable_id', $seller->id)->where('data', 'like', '%"event":"bordereau_ready"%')->first();
        $this->assertNotNull($note, 'bell entry');
        $data = json_decode($note->data, true);
        $this->assertSame('/seller/orders?order=' . $so->id, $data['link'], 'links to the order page, not the PDF');
        $this->assertSame(SellerBordereauReadyNotification::class, $note->type);
    }

    public function test_bulk_merges_own_parcels_and_skips_the_rest(): void
    {
        $seller = $this->sellerWithPickup();
        $a = $this->confirmedParcel($seller);
        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $b = $this->confirmedParcel($seller);
        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $notPrepared = $this->confirmedParcel($seller);
        $this->prepare($a, $seller);
        $this->prepare($b, $seller);

        $other = $this->sellerWithPickup(4);
        $this->travel(CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $foreign = $this->confirmedParcel($other);
        $this->prepare($foreign, $other);

        $res = $this->as($seller)->post('/api/seller/bordereaux/bulk', ['ids' => [$a->id, $b->id, $notPrepared->id, $foreign->id]]);
        $res->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertSame("{$a->id},{$b->id}", $res->headers->get('X-Bordereaux-Printed'));
        $this->assertSame("{$notPrepared->id},{$foreign->id}", $res->headers->get('X-Bordereaux-Skipped'));

        $this->assertSame(1, $this->so($a)->bordereau_download_count);
        $this->assertSame(1, $this->so($b)->bordereau_download_count);
        $this->assertSame(0, $this->so($foreign)->bordereau_download_count);
        $this->assertSame(2, $this->adminNotes('intigo_bordereau_printed'), 'one per parcel');

        // Nothing printable → 404
        $this->as($seller)->postJson('/api/seller/bordereaux/bulk', ['ids' => [$notPrepared->id, $foreign->id]])->assertStatus(404);
    }

    // ── INTIGO_BORDEREAU_ENABLED=false ─────────────────────────────────────

    public function test_flag_is_off_by_default(): void
    {
        // No INTIGO_BORDEREAU_ENABLED in the environment → off
        $this->assertNull(env('INTIGO_BORDEREAU_ENABLED'));
        $this->assertFalse((require config_path('intigo.php'))['bordereau']['enabled']);
    }

    public function test_flag_off_never_calls_intigo_and_nothing_is_available_until_an_upload(): void
    {
        config(['intigo.bordereau.enabled' => false]);
        $seller = $this->sellerWithPickup();
        $so = $this->confirmedParcel($seller);
        $this->prepare($so, $seller);

        // Parcel created but no PDF: no "bordereau ready" notification (nothing claimed either)
        $this->assertSame(0, DB::table('seller_order_notifications')->where('seller_order_id', $so->id)
            ->where(fn ($q) => $q->where('event', 'bordereau_ready')->orWhere('event', 'like', 'bdx:%'))->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $seller->id)->where('data', 'like', '%"event":"bordereau_%')->count());

        // Seller routes: 404 not available yet, Intigo never called
        $this->as($seller)->getJson("/api/seller/orders/{$so->id}/bordereau")
            ->assertStatus(404)->assertJson(['code' => 'not_available']);
        $this->as($seller)->postJson('/api/seller/bordereaux/bulk', ['ids' => [$so->id]])
            ->assertStatus(404)->assertJson(['code' => 'not_available']);
        $this->as($this->admin)->getJson("/api/admin/seller-orders/{$so->id}/bordereau")->assertStatus(404);
        $this->assertSame(0, DB::table('bordereau_downloads')->count());
        $this->assertSame(0, DB::table('intigo_logs')->where('endpoint', 'like', '/secure-api/bordereaux/%')->count());

        // Order list: nothing printable yet
        $item = collect($this->as($seller)->getJson('/api/seller/orders')->json('data.data'))->firstWhere('id', $so->id);
        $this->assertFalse($item['delivery']['bordereau']['available']);
        $this->assertFalse($item['delivery']['bordereau']['enabled']);
        $order = collect($this->as($this->admin)->getJson('/api/admin/orders?per_page=100')->json('data.data'))->firstWhere('id', $so->order_id);
        $badge = collect($order['parcels_summary'])->firstWhere('id', $so->id)['bordereau'];
        $this->assertFalse($badge['available']);
        $this->assertTrue($badge['can_upload']);
    }
}
