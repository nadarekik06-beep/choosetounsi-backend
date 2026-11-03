<?php

namespace Tests\Feature\Intigo;

use App\Notifications\Orders\SellerBordereauReadyNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Tests\TestCase;

/**
 * Manual bordereau: the admin uploads the PDF per seller_order, the seller is told
 * and prints it. Works with INTIGO_BORDEREAU_ENABLED=false (the default here).
 */
class ManualBordereauTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        config(['intigo.alert_email' => 'alerts@example.com', 'intigo.bordereau.enabled' => false]);
        Mail::fake();
        Storage::fake('local');
    }

    private function pdfBytes(string $label = 'TEST'): string
    {
        $tmp = storage_path('app/mpdf');
        if (!is_dir($tmp)) mkdir($tmp, 0775, true);
        $m = new Mpdf(['tempDir' => $tmp]);
        $m->WriteHTML('<h1>' . e($label) . '</h1>');
        return $m->Output('', 'S');
    }

    private function pdfFile(string $label = 'TEST', string $name = 'bordereau.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pdfBytes($label));
    }

    /** One order with two sellers, both prepared. @return array{0: object, 1: object, 2: \App\Models\User, 3: \App\Models\User} */
    private function twoSellerOrder(): array
    {
        $a = $this->sellerWithPickup(3);
        $b = $this->sellerWithPickup(4);
        $this->addToCart($this->product($a, 40, false, 10), 1);
        $this->addToCart($this->product($b, 25, false, 10), 1);
        [, $orderId] = $this->placeAndConfirm();
        $soA = $this->parcel($orderId, $a);
        $soB = $this->parcel($orderId, $b);
        $this->prepare($soA, $a);
        $this->prepare($soB, $b);
        return [$soA, $soB, $a, $b];
    }

    private function upload(object $so, UploadedFile $file)
    {
        return $this->as($this->admin)->post("/api/admin/seller-orders/{$so->id}/bordereau", ['file' => $file], ['Accept' => 'application/json']);
    }

    private function sellerBells($seller, string $event): int
    {
        return DB::table('notifications')->where('notifiable_id', $seller->id)
            ->where('type', SellerBordereauReadyNotification::class)->where('data', 'like', '%"event":"' . $event . '"%')->count();
    }

    private function adminNotes(string $type): int
    {
        return DB::table('notifications')->where('notifiable_id', $this->admin->id)
            ->where('data', 'like', '%"type":"' . $type . '"%')->count();
    }

    public function test_each_upload_goes_to_the_right_seller_and_sellers_only_see_their_own(): void
    {
        [$soA, $soB, $a, $b] = $this->twoSellerOrder();

        $this->upload($soA, $this->pdfFile('A', 'intigo-A.pdf'))->assertOk()->assertJsonPath('data.has_upload', true);
        $this->upload($soB, $this->pdfFile('B'))->assertOk();

        $this->assertSame('manual', $this->so($soA)->bordereau_source);
        $this->assertSame('intigo-A.pdf', $this->so($soA)->bordereau_original_name);
        $this->assertNotSame($this->so($soA)->bordereau_path, $this->so($soB)->bordereau_path);
        Storage::disk('local')->assertExists($this->so($soA)->bordereau_path);

        // A prints A's file, never B's
        $resA = $this->as($a)->get("/api/seller/orders/{$soA->id}/bordereau");
        $resA->assertOk();
        $this->assertSame(Storage::disk('local')->get($this->so($soA)->bordereau_path), $resA->getContent());
        $this->as($a)->get("/api/seller/orders/{$soB->id}/bordereau")->assertStatus(403);
        $this->as($b)->get("/api/seller/orders/{$soA->id}/bordereau")->assertStatus(403);
        $this->as($b)->get("/api/seller/orders/{$soB->id}/bordereau")->assertOk();

        // Seller list fields
        $item = collect($this->as($a)->getJson('/api/seller/orders')->json('data.data'))->firstWhere('id', $soA->id);
        $this->assertTrue($item['delivery']['bordereau']['available']);
        $this->assertSame('manual', $item['delivery']['bordereau']['source']);
        $this->assertFalse($item['delivery']['bordereau']['is_update']);
    }

    public function test_seller_notified_on_upload_and_on_replace_with_the_flag_off(): void
    {
        [$soA, , $a] = $this->twoSellerOrder();

        $this->upload($soA, $this->pdfFile('v1'))->assertOk();
        $this->assertSame(1, $this->sellerBells($a, 'bordereau_ready'));
        $this->assertNotNull($this->so($soA)->bordereau_seller_notified_at, 'notified_at set once the bell is saved');
        $data = json_decode(DB::table('notifications')->where('notifiable_id', $a->id)->where('type', SellerBordereauReadyNotification::class)->value('data'), true);
        $this->assertSame('/seller/orders?order=' . $soA->id, $data['link'], 'links to the order page, not the file');

        // Seller opens it, then the admin replaces it
        $this->as($a)->get("/api/seller/orders/{$soA->id}/bordereau")->assertOk();
        $this->assertSame(1, $this->so($soA)->bordereau_download_count);
        $oldPath = $this->so($soA)->bordereau_path;

        $this->upload($soA, $this->pdfFile('v2'))->assertOk()->assertJsonPath('data.versions', 2);
        $fresh = $this->so($soA);
        $this->assertSame(1, $this->sellerBells($a, 'bordereau_updated'), '"updated" notification');
        $this->assertSame(0, $fresh->bordereau_download_count, 'seen state reset');
        $this->assertNull($fresh->bordereau_first_downloaded_at);
        $this->assertNotNull($fresh->bordereau_seller_notified_at);
        Storage::disk('local')->assertExists($oldPath);   // old file kept in the history
        $this->assertSame(2, DB::table('bordereau_uploads')->where('seller_order_id', $soA->id)->count());
        $this->assertNotNull(DB::table('bordereau_uploads')->where('path', $oldPath)->value('replaced_at'));

        $item = collect($this->as($a)->getJson('/api/seller/orders')->json('data.data'))->firstWhere('id', $soA->id);
        $this->assertTrue($item['delivery']['bordereau']['is_update']);
    }

    public function test_seller_print_counts_and_admins_told_on_first_print_of_each_file(): void
    {
        [$soA, , $a] = $this->twoSellerOrder();
        $this->upload($soA, $this->pdfFile('v1'))->assertOk();

        $this->as($a)->get("/api/seller/orders/{$soA->id}/bordereau")->assertOk();
        $this->as($a)->get("/api/seller/orders/{$soA->id}/bordereau")->assertOk();
        $this->assertSame(2, $this->so($soA)->bordereau_download_count);
        $this->assertSame(1, $this->adminNotes('intigo_bordereau_printed'));

        // Admin preview: logged, not counted, no notification
        $this->as($this->admin)->get("/api/admin/seller-orders/{$soA->id}/bordereau")->assertOk();
        $this->assertSame(2, $this->so($soA)->bordereau_download_count);
        $this->assertSame(1, $this->adminNotes('intigo_bordereau_printed'));

        // A replaced file: its first print is told again
        $this->upload($soA, $this->pdfFile('v2'))->assertOk();
        $this->as($a)->get("/api/seller/orders/{$soA->id}/bordereau")->assertOk();
        $this->assertSame(2, $this->adminNotes('intigo_bordereau_printed'));

        // Admin badge: 3 steps
        $order = collect($this->as($this->admin)->getJson('/api/admin/orders?per_page=100')->json('data.data'))->firstWhere('id', $soA->order_id);
        $badge = collect($order['parcels_summary'])->firstWhere('id', $soA->id)['bordereau'];
        $this->assertTrue($badge['has_upload']);
        $this->assertSame($this->admin->id, $badge['uploaded_by']['id']);
        $this->assertNotNull($badge['notified_at']);
        $this->assertSame(1, $badge['print_count']);
    }

    public function test_delete_hides_it_from_the_seller_and_keeps_history(): void
    {
        [$soA, , $a] = $this->twoSellerOrder();
        $this->upload($soA, $this->pdfFile())->assertOk();
        $path = $this->so($soA)->bordereau_path;

        $this->as($this->admin)->deleteJson("/api/admin/seller-orders/{$soA->id}/bordereau")->assertOk()->assertJsonPath('data.has_upload', false);

        $this->as($a)->getJson("/api/seller/orders/{$soA->id}/bordereau")->assertStatus(404)->assertJson(['code' => 'not_available']);
        $this->assertNull($this->so($soA)->bordereau_source);
        $this->assertNotNull(DB::table('bordereau_uploads')->where('path', $path)->value('removed_at'));
        Storage::disk('local')->assertExists($path);
    }

    public function test_upload_validation(): void
    {
        [$soA] = $this->twoSellerOrder();
        $url = "/api/admin/seller-orders/{$soA->id}/bordereau";

        // Not a PDF / renamed text file / too big / missing
        $this->upload($soA, UploadedFile::fake()->image('photo.jpg'))->assertStatus(422);
        $this->upload($soA, UploadedFile::fake()->createWithContent('fake.pdf', 'just some text, not a pdf'))->assertStatus(422);
        $this->upload($soA, UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->as($this->admin)->postJson($url, [])->assertStatus(422);
        $this->assertNull($this->so($soA)->bordereau_source);
        $this->assertSame(0, DB::table('bordereau_uploads')->count());
    }

    public function test_only_admins_can_upload_and_only_on_a_prepared_live_order(): void
    {
        [$soA, , $a] = $this->twoSellerOrder();
        $this->as($a)->post("/api/admin/seller-orders/{$soA->id}/bordereau", ['file' => $this->pdfFile()], ['Accept' => 'application/json'])->assertStatus(403);

        // Not prepared yet
        $c = $this->sellerWithPickup(5);
        $this->travel(\App\Services\Orders\CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $this->addToCart($this->product($c, 30, false, 10), 1);
        [, $orderId] = $this->placeAndConfirm();
        $soC = $this->parcel($orderId, $c);
        $this->upload($soC, $this->pdfFile())->assertStatus(422)->assertJson(['code' => 'not_allowed']);

        // Cancelled
        DB::table('seller_orders')->where('id', $soA->id)->update(['status' => 'cancelled']);
        $this->upload($soA, $this->pdfFile())->assertStatus(422)->assertJson(['code' => 'not_allowed']);
        $this->assertSame(0, DB::table('bordereau_uploads')->count());
    }

    public function test_bulk_merges_only_uploaded_pdfs(): void
    {
        [$soA, , $a] = $this->twoSellerOrder();
        $this->travel(\App\Services\Orders\CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $this->addToCart($this->product($a, 33, false, 10), 1);
        [, $orderId] = $this->placeAndConfirm();
        $soA2 = $this->parcel($orderId, $a);
        $this->prepare($soA2, $a);
        $this->travel(\App\Services\Orders\CheckoutGuard::RECENT_SECONDS + 1)->seconds();
        $this->addToCart($this->product($a, 34, false, 10), 1);
        [, $orderId3] = $this->placeAndConfirm();
        $soNone = $this->parcel($orderId3, $a);

        $this->upload($soA, $this->pdfFile('A1'))->assertOk();

        // Single PDF: served as is (no merge)
        $one = $this->as($a)->post('/api/seller/bordereaux/bulk', ['ids' => [$soA->id, $soNone->id]]);
        $one->assertOk();
        $this->assertSame(Storage::disk('local')->get($this->so($soA)->bordereau_path), $one->getContent());
        $this->assertSame((string) $soNone->id, $one->headers->get('X-Bordereaux-Skipped'));

        $this->upload($soA2, $this->pdfFile('A2'))->assertOk();
        $two = $this->as($a)->post('/api/seller/bordereaux/bulk', ['ids' => [$soA->id, $soA2->id, $soNone->id]]);
        $two->assertOk();
        $this->assertStringStartsWith('%PDF', $two->getContent());
        $this->assertSame("{$soA->id},{$soA2->id}", $two->headers->get('X-Bordereaux-Printed'));
    }

    public function test_admin_filters_missing_and_uploaded_not_printed(): void
    {
        [$soA, $soB, $a] = $this->twoSellerOrder();
        $ids = fn (string $f) => collect($this->as($this->admin)->getJson("/api/admin/orders?bordereau={$f}&per_page=100")->json('data.data'))->pluck('id')->all();

        $this->assertContains($soA->order_id, $ids('missing'));
        $this->assertNotContains($soA->order_id, $ids('uploaded_not_printed'));

        $this->upload($soA, $this->pdfFile())->assertOk();
        $this->upload($soB, $this->pdfFile())->assertOk();
        $this->assertNotContains($soA->order_id, $ids('missing'));
        $this->assertContains($soA->order_id, $ids('uploaded_not_printed'));

        $this->as($a)->get("/api/seller/orders/{$soA->id}/bordereau")->assertOk();
        $this->assertContains($soA->order_id, $ids('uploaded_not_printed'), 'seller B has not printed yet');
    }

    public function test_resend_notification(): void
    {
        [$soA, , $a] = $this->twoSellerOrder();
        $this->upload($soA, $this->pdfFile())->assertOk();
        DB::table('seller_orders')->where('id', $soA->id)->update(['bordereau_seller_notified_at' => null, 'bordereau_notify_error' => 'Notification failed: SMTP down']);

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$soA->id}/bordereau/notify")->assertOk();
        $this->assertSame(2, $this->sellerBells($a, 'bordereau_ready'));
        $this->assertNotNull($this->so($soA)->bordereau_seller_notified_at);
        $this->assertNull($this->so($soA)->bordereau_notify_error);
    }
}
