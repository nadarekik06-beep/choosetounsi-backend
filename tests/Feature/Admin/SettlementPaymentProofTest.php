<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Notifications\Sellers\SettlementPaidNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proof of payment on settlement batches: the admin attaches a receipt (at creation, when
 * marking the batch paid, or later), the seller sees only their own, the file is private.
 *
 * Run:  php vendor/bin/phpunit tests/Feature/Admin/SettlementPaymentProofTest.php
 */
class SettlementPaymentProofTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $seller;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('choosetounsi_test', DB::connection()->getDatabaseName());
        Storage::fake('private');
        Notification::fake();
        $this->admin  = $this->makeUser('admin');
        $this->seller = $this->makeUser('seller');
        $this->other  = $this->makeUser('seller');
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' ' . Str::random(5), 'email' => $role . '_' . Str::random(10) . '@test.local',
            'password' => bcrypt('secret-password'), 'role' => $role, 'is_active' => true, 'locale' => 'fr',
        ]);
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);
        return $this;
    }

    private function png(string $name = 'receipt.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        ));
    }

    private function pdf(string $name = 'virement.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function batch(User $seller, string $status = 'paid', array $extra = []): object
    {
        $id = DB::table('settlement_batches')->insertGetId([
            'batch_reference' => 'BATCH-T-' . Str::random(8),
            'seller_id' => $seller->id, 'batch_date' => now()->toDateString(),
            'total_seller_payout' => 123.456, 'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ] + $extra);
        return DB::table('settlement_batches')->find($id);
    }

    private function proofUrl(object $b): string { return "/api/admin/settlements/{$b->id}/payment-proof"; }

    // ── Admin: attach ─────────────────────────────────────────────────────────

    public function test_proof_can_be_attached_when_creating_the_batch_and_seller_is_notified_when_it_is_paid(): void
    {
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'T-' . Str::random(8), 'total_amount' => 50, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('seller_orders')->insert([
            'order_id' => $orderId, 'seller_id' => $this->seller->id, 'payout_status' => 'ready',
            'money_received_at' => now(), 'subtotal' => 50, 'seller_net_amount' => 40, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->as($this->admin)->post('/api/admin/settlements/create', [
            'seller_id' => $this->seller->id, 'batch_date' => now()->addDays(5)->toDateString(),
            'payment_method' => 'bank_transfer', 'payment_reference' => 'VIR-889', 'payment_date' => now()->toDateString(),
            'proof' => $this->png(),
        ], ['Accept' => 'application/json'])->assertOk();

        $data = $res->json('data');
        $this->assertTrue($data['has_proof']);
        $this->assertArrayNotHasKey('proof_path', $data);
        $this->assertEqualsWithDelta(40, (float) $data['total_seller_payout'], 0.001, 'amount untouched');

        $row = DB::table('settlement_batches')->find($data['id']);
        $this->assertSame('draft', $row->status);
        $this->assertSame($this->admin->id, (int) $row->proof_uploaded_by);
        $this->assertNotNull($row->proof_uploaded_at);
        $this->assertStringStartsWith('settlement-proofs/', $row->proof_path);
        $this->assertStringEndsWith('.png', $row->proof_path);
        $this->assertStringNotContainsString('receipt', $row->proof_path, 'random name, not the client name');
        Storage::disk('private')->assertExists($row->proof_path);
        Storage::disk('public')->assertMissing($row->proof_path);
        Notification::assertNothingSent();      // not paid yet

        $this->as($this->admin)->postJson("/api/admin/settlements/{$data['id']}/confirm")->assertOk();
        Notification::assertSentTo($this->seller, SettlementPaidNotification::class);
        $this->assertEqualsWithDelta(40, (float) DB::table('settlement_batches')->find($data['id'])->total_seller_payout, 0.001);
    }

    public function test_mark_as_paid_with_proof_stores_details_and_notifies(): void
    {
        $b = $this->batch($this->seller, 'draft');

        $this->as($this->admin)->post("/api/admin/settlements/{$b->id}/confirm", [
            'payment_method' => 'd17', 'payment_reference' => 'D17-1', 'payment_date' => '2026-11-10', 'proof' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertOk();

        $row = DB::table('settlement_batches')->find($b->id);
        $this->assertSame('paid', $row->status);
        $this->assertSame('d17', $row->payment_method);
        $this->assertSame('D17-1', $row->payment_reference);
        $this->assertSame('application/pdf', $row->proof_mime);
        Storage::disk('private')->assertExists($row->proof_path);
        Notification::assertSentTo($this->seller, SettlementPaidNotification::class, function ($n) {
            return $n->amount === '123,456' && $n->batchId > 0;
        });
    }

    public function test_mark_as_paid_without_proof_still_works_and_sends_no_receipt_notice(): void
    {
        $b = $this->batch($this->seller, 'draft');

        $this->as($this->admin)->postJson("/api/admin/settlements/{$b->id}/confirm")->assertOk();

        $row = DB::table('settlement_batches')->find($b->id);
        $this->assertSame('paid', $row->status);
        $this->assertNull($row->proof_path);
        Notification::assertNothingSent();
    }

    public function test_proof_can_be_added_later_then_replaced_and_removed(): void
    {
        $b = $this->batch($this->seller, 'paid');

        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->png(), 'payment_method' => 'bank_transfer'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_proof', true);
        $first = DB::table('settlement_batches')->find($b->id);
        Storage::disk('private')->assertExists($first->proof_path);
        Notification::assertSentToTimes($this->seller, SettlementPaidNotification::class, 1);

        // replace: old file gone, new one stored, seller told again, uploader recorded
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->pdf()], ['Accept' => 'application/json'])->assertOk();
        $second = DB::table('settlement_batches')->find($b->id);
        $this->assertNotSame($first->proof_path, $second->proof_path);
        Storage::disk('private')->assertMissing($first->proof_path);
        Storage::disk('private')->assertExists($second->proof_path);
        $this->assertSame($this->admin->id, (int) $second->proof_uploaded_by);
        Notification::assertSentToTimes($this->seller, SettlementPaidNotification::class, 2);

        // admin can view it
        $this->as($this->admin)->get($this->proofUrl($b))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // remove
        $this->as($this->admin)->deleteJson($this->proofUrl($b))->assertOk();
        $this->assertNull(DB::table('settlement_batches')->find($b->id)->proof_path);
        Storage::disk('private')->assertMissing($second->proof_path);
        $this->as($this->admin)->getJson($this->proofUrl($b))->assertNotFound();
    }

    public function test_cancelling_a_draft_removes_its_proof_file_and_cancelled_batches_refuse_one(): void
    {
        $b = $this->batch($this->seller, 'draft');
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $path = DB::table('settlement_batches')->find($b->id)->proof_path;

        $this->as($this->admin)->postJson("/api/admin/settlements/{$b->id}/cancel")->assertOk();
        Storage::disk('private')->assertMissing($path);
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->png()], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_admin_list_flags_batches_with_a_proof_without_leaking_the_path(): void
    {
        $b = $this->batch($this->seller, 'paid');
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->png()], ['Accept' => 'application/json'])->assertOk();
        $plain = $this->batch($this->seller, 'paid');

        $rows = collect($this->as($this->admin)->getJson('/api/admin/settlements?seller_id=' . $this->seller->id)->assertOk()->json('data.data'))->keyBy('id');
        $this->assertTrue($rows[$b->id]['has_proof']);
        $this->assertFalse($rows[$plain->id]['has_proof']);
        $this->assertArrayNotHasKey('proof_path', $rows[$b->id]);

        $this->as($this->admin)->getJson("/api/admin/settlements/{$b->id}")->assertOk()
            ->assertJsonPath('data.has_proof', true);
        $this->assertArrayNotHasKey('proof_path', $this->as($this->admin)->getJson("/api/admin/settlements/{$b->id}")->json('data'));
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_wrong_real_type_or_too_big_files_are_rejected(): void
    {
        $b = $this->batch($this->seller, 'paid');
        $h = ['Accept' => 'application/json'];

        // Real temp files (the framework's fake file guesses its type from the extension, a real upload is sniffed):
        // text / script content dressed up as a PDF or JPG, claiming a good client MIME type
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->real('<?php echo 1;', 'fake.pdf', 'application/pdf')], $h)->assertStatus(422)->assertJsonValidationErrors('proof');
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->real('just text', 'fake.jpg', 'image/jpeg')], $h)->assertStatus(422);
        // a real image of a type we do not accept (GIF), named .png
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->real(base64_decode('R0lGODlhAQABAAAAACwAAAAAAQABAAACAkQBADs='), 'a.png', 'image/png')], $h)->assertStatus(422);
        // a real PNG renamed .exe is accepted by content and stored as .png
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->real(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 'x.exe', 'application/octet-stream')], $h)->assertOk();
        $this->assertStringEndsWith('.png', DB::table('settlement_batches')->find($b->id)->proof_path);
        $this->as($this->admin)->deleteJson($this->proofUrl($b))->assertOk();
        Notification::assertSentToTimes($this->seller, SettlementPaidNotification::class, 1);
        // > 5 MB
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')], $h)->assertStatus(422);
        // bad method
        $this->as($this->admin)->post($this->proofUrl($b), ['payment_method' => 'bitcoin'], $h)->assertStatus(422);

        $this->assertNull(DB::table('settlement_batches')->find($b->id)->proof_path);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    private function real(string $content, string $name, string $clientMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($path, $content);
        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_seller_sees_only_their_own_proof(): void
    {
        $mine  = $this->batch($this->seller, 'paid');
        $theirs = $this->batch($this->other, 'paid');
        foreach ([$mine, $theirs] as $b) {
            $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->png(), 'payment_method' => 'bank_transfer', 'payment_reference' => 'REF-' . $b->id], ['Accept' => 'application/json'])->assertOk();
        }

        // own proof: streamed, private
        $this->as($this->seller)->get("/api/seller/earnings/settlement/{$mine->id}/proof")
            ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        // somebody else's: 403, nothing leaked
        $this->as($this->seller)->getJson("/api/seller/earnings/settlement/{$theirs->id}/proof")->assertForbidden();
        $this->as($this->other)->getJson("/api/seller/earnings/settlement/{$mine->id}/proof")->assertForbidden();

        // seller finance history: details + has_proof, never the path / uploader
        $rows = $this->as($this->seller)->getJson('/api/seller/earnings/history')->assertOk()->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['has_proof']);
        $this->assertSame('bank_transfer', $rows[0]['payment_method']);
        $this->assertSame('REF-' . $mine->id, $rows[0]['payment_reference']);
        $this->assertSame('image/png', $rows[0]['proof_mime']);
        foreach (['proof_path', 'proof_uploaded_by', 'proof_original_name'] as $secret) {
            $this->assertArrayNotHasKey($secret, $rows[0]);
        }
        $one = $this->as($this->seller)->getJson("/api/seller/earnings/settlement/{$mine->id}")->assertOk()->json('data');
        $this->assertTrue($one['has_proof']);
        $this->assertArrayNotHasKey('proof_path', $one);
    }

    public function test_seller_cannot_use_the_admin_endpoints_and_guests_get_nothing(): void
    {
        $b = $this->batch($this->seller, 'paid');
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->png()], ['Accept' => 'application/json'])->assertOk();

        // seller on admin routes
        $this->as($this->seller)->getJson($this->proofUrl($b))->assertForbidden();
        $this->as($this->seller)->postJson($this->proofUrl($b), [])->assertForbidden();
        $this->as($this->seller)->deleteJson($this->proofUrl($b))->assertForbidden();
        $this->assertNotNull(DB::table('settlement_batches')->find($b->id)->proof_path);

        // guests
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson($this->proofUrl($b))->assertUnauthorized();
        $this->getJson("/api/seller/earnings/settlement/{$b->id}/proof")->assertUnauthorized();
        $this->postJson($this->proofUrl($b), [])->assertUnauthorized();
        $this->deleteJson($this->proofUrl($b))->assertUnauthorized();
    }

    public function test_seller_does_not_see_a_proof_attached_to_an_unpaid_batch(): void
    {
        $b = $this->batch($this->seller, 'draft');
        $this->as($this->admin)->post($this->proofUrl($b), ['proof' => $this->png()], ['Accept' => 'application/json'])->assertOk();

        $this->as($this->seller)->getJson("/api/seller/earnings/settlement/{$b->id}/proof")->assertNotFound();
        $row = $this->as($this->seller)->getJson('/api/seller/earnings/history')->json('data.data.0');
        $this->assertFalse($row['has_proof']);
    }

    public function test_the_private_disk_is_not_web_accessible(): void
    {
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.private.root'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.private'));
        $this->assertNotContains(storage_path('app/private'), array_values(config('filesystems.links')));
    }
}
