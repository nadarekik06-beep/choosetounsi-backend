<?php

namespace Tests\Feature\Intigo;

use App\Jobs\Intigo\SendIntigoParcel;
use App\Services\Intigo\ParcelSender;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A duplicate parcel is a real driver trip and real fees: never two parcels
 * for one seller_order, whatever the clicks, retries and lost answers.
 */
class IntigoNoDuplicateTest extends TestCase
{
    use DatabaseTransactions, IntigoTestHelpers;

    private ScriptedIntigoClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntigo();
        $this->client = $this->scripted();
    }

    /** @return array{0: object, 1: \App\Models\User} a confirmed parcel and its seller */
    private function confirmedParcel(): array
    {
        $seller = $this->sellerWithPickup();
        $this->addToCart($this->product($seller, 40));
        [, $orderId] = $this->placeAndConfirm();
        return [$this->parcel($orderId, $seller), $seller];
    }

    public function test_double_clicks_jobs_and_admin_retries_create_a_single_parcel(): void
    {
        [$so, $seller] = $this->confirmedParcel();

        $this->prepare($so, $seller);
        $this->prepare($so, $seller);
        (new SendIntigoParcel($so->id))->handle(app(ParcelSender::class));
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/retry")->assertOk()->assertJsonPath('result', 'already_sent');

        $this->assertCount(1, $this->client->calls['create']);
        $this->assertSame('SCR-1', $this->so($so)->intigo_nid);
    }

    public function test_a_send_in_progress_is_never_doubled(): void
    {
        [$so] = $this->confirmedParcel();
        DB::table('seller_orders')->where('id', $so->id)->update(['intigo_locked_at' => now(), 'intigo_sync_status' => 'pending']);

        $this->assertSame('busy', app(ParcelSender::class)->send($so->id)['result']);
        $this->assertCount(0, $this->client->calls['create']);

        // A claim older than the lock window is dead: the send goes ahead
        DB::table('seller_orders')->where('id', $so->id)->update(['intigo_locked_at' => now()->subMinutes(10)]);
        $this->assertSame('sent', app(ParcelSender::class)->send($so->id)['result']);
        $this->assertCount(1, $this->client->calls['create']);
    }

    public function test_a_lost_answer_is_resolved_by_cid_lookup_not_by_a_second_parcel(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->client->onCreate = [ScriptedIntigoClient::timeoutAfterCreating('LOST-77')];

        $this->prepare($so, $seller);

        $this->assertCount(1, $this->client->calls['create']);
        $this->assertSame(['CT-SO-' . $so->id], $this->client->calls['findByCid']);
        $fresh = $this->so($so);
        $this->assertSame('LOST-77', $fresh->intigo_nid);
        $this->assertSame('sent', $fresh->intigo_sync_status);
    }

    public function test_a_timeout_without_a_parcel_is_retried_with_a_lookup_first(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->client->onCreate = [ScriptedIntigoClient::timeoutWithoutCreating()];

        $this->prepare($so, $seller);
        $fresh = $this->so($so);
        $this->assertSame('pending', $fresh->intigo_sync_status);
        $this->assertNull($fresh->intigo_nid);
        $this->assertStringContainsString('Retrying automatically', $fresh->intigo_error);
        $this->assertSame(1, $fresh->intigo_attempts);

        // The job's next attempt: cid lookup first (nothing there), then create
        (new SendIntigoParcel($so->id))->handle(app(ParcelSender::class));
        $this->assertCount(2, $this->client->calls['create']);
        $this->assertCount(2, $this->client->calls['findByCid'], 'after the timeout, then before re-sending');
        $this->assertSame('SCR-2', $this->so($so)->intigo_nid);
    }

    public function test_the_job_gives_up_after_the_maximum_attempts(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->client->onCreate = array_fill(0, 10, ScriptedIntigoClient::fails(503, ['detail' => 'Service Unavailable']));

        $this->prepare($so, $seller);
        for ($i = 0; $i < 5; $i++) {
            (new SendIntigoParcel($so->id))->handle(app(ParcelSender::class));
        }

        $this->assertCount(config('intigo.send.max_attempts'), $this->client->calls['create']);
        $fresh = $this->so($so);
        $this->assertSame('failed', $fresh->intigo_sync_status);
        $this->assertStringContainsString('Gave up after 4 attempts', $fresh->intigo_error);
    }

    public function test_validation_and_auth_errors_are_never_retried(): void
    {
        foreach ([
            [400, ['detail' => [['loc' => ['body', 'city_name'], 'msg' => 'Ville inconnue', 'type' => 'value_error']]], 'city_name: Ville inconnue'],
            [400, ['detail' => 'Ville inconnue'], 'Rejected by Intigo: Ville inconnue'],
            [401, ['detail' => 'Invalid API key'], 'refused the API key'],
            [402, ['detail' => 'Solde insuffisant'], 'insufficient account balance'],
            [404, ['detail' => 'Pickup introuvable'], 'Not found at Intigo'],
        ] as [$status, $body, $message]) {
            $this->client = $this->scripted();
            [$so, $seller] = $this->confirmedParcel();
            $this->client->onCreate = [ScriptedIntigoClient::fails($status, $body)];

            $this->prepare($so, $seller);
            (new SendIntigoParcel($so->id))->handle(app(ParcelSender::class));   // a stray job retry

            $fresh = $this->so($so);
            $this->assertSame('failed', $fresh->intigo_sync_status, "HTTP {$status}");
            $this->assertStringContainsString($message, $fresh->intigo_error, "HTTP {$status}");
            $this->assertCount(1, $this->client->calls['create'], "HTTP {$status}: never retried automatically");
        }
    }

    public function test_an_admin_retry_after_a_fixed_error_sends_and_still_checks_the_cid(): void
    {
        [$so, $seller] = $this->confirmedParcel();
        $this->client->onCreate = [ScriptedIntigoClient::fails(400, ['detail' => 'Ville inconnue'])];
        $this->prepare($so, $seller);
        $this->assertSame('failed', $this->so($so)->intigo_sync_status);

        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/intigo/retry")->assertOk()
            ->assertJsonPath('result', 'sent')->assertJsonPath('data.nid', 'SCR-2');
        $this->assertContains('CT-SO-' . $so->id, $this->client->calls['findByCid']);
    }

    public function test_a_cancelled_parcel_is_not_sent(): void
    {
        [$so] = $this->confirmedParcel();
        DB::table('seller_orders')->where('id', $so->id)->update(['intigo_sync_status' => 'pending']);
        $this->as($this->admin)->postJson("/api/admin/seller-orders/{$so->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertSame('skipped', app(ParcelSender::class)->send($so->id)['result']);
        $this->assertCount(0, $this->client->calls['create']);
        $this->assertNull($this->so($so)->intigo_sync_status);
    }

    public function test_the_nid_is_unique_across_parcels(): void
    {
        [$a] = $this->confirmedParcel();
        [$b] = $this->confirmedParcel();
        DB::table('seller_orders')->where('id', $a->id)->update(['intigo_nid' => 'DUP-1']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('seller_orders')->where('id', $b->id)->update(['intigo_nid' => 'DUP-1']);
    }
}
