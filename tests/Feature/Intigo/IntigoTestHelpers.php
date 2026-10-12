<?php

namespace Tests\Feature\Intigo;

use App\Models\SellerOrder;
use App\Models\User;
use App\Services\Intigo\IntigoClient;
use App\Services\Intigo\IntigoRegions;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Delivery\DeliveryTestHelpers;

/**
 * Intigo fixtures on top of the delivery ones. Fake mode everywhere: no test
 * ever reaches the network (FakeIntigoClient, or ScriptedIntigoClient bound
 * in the container).
 */
trait IntigoTestHelpers
{
    use DeliveryTestHelpers;

    protected const SECRET = 'test-webhook-secret';

    protected function setUpIntigo(): void
    {
        config([
            'intigo.mode'                  => 'fake',
            'intigo.api_key'               => null,
            'intigo.webhook_secret'        => self::SECRET,
            'intigo.platform_pickup_index' => 0,
            'mail.default'                 => 'array',
        ]);
        $this->setUpDelivery();
        $this->runAfterCommitCallbacksInsideTheTestTransaction();
    }

    /**
     * DatabaseTransactions wraps each test in a transaction that never commits,
     * so Laravel 8 would hold every afterCommit() job / callback forever. This
     * manager ignores the test's own transaction (swapped in after it began)
     * and runs callbacks when the application's transaction commits, as in
     * production. Same as SellerOrderNotificationsTest.
     */
    private function runAfterCommitCallbacksInsideTheTestTransaction(): void
    {
        $manager = new class extends DatabaseTransactionsManager {
            public function committedTo(string $connection, int $level): void
            {
                [$done, $open] = $this->transactions->partition(
                    fn ($t) => $t->connection == $connection && $t->level > $level
                );
                $this->transactions = $open->values();
                $done->each->executeCallbacks();
            }
        };
        $this->app->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
        Event::listen(TransactionCommitted::class, fn ($e) => $manager->committedTo($e->connectionName, $e->connection->transactionLevel()));
    }

    protected function scripted(): ScriptedIntigoClient
    {
        $client = new ScriptedIntigoClient();
        $this->app->instance(IntigoClient::class, $client);
        return $client;
    }

    protected function sellerWithPickup(int $index = 3, string $plan = 'free'): User
    {
        $seller = $this->seller($plan);
        $seller->forceFill(['intigo_pickup_index' => $index, 'phone' => '98000111'])->save();
        return $seller;
    }

    /** Checkout of the cart, then the admin confirms the order. @return array{0: array, 1: int} checkout response, order id */
    protected function placeAndConfirm(array $body = []): array
    {
        $res = $this->checkout($body);
        $this->as($this->admin)->patchJson("/api/admin/orders/{$res['order_id']}/confirm-order", ['action' => 'confirmed'])->assertOk();
        return [$res, (int) $res['order_id']];
    }

    protected function prepare(object $so, User $seller): \Illuminate\Testing\TestResponse
    {
        return $this->as($seller)->postJson("/api/seller/orders/{$so->id}/prepared")->assertOk();
    }

    protected function so(object|int $so): SellerOrder
    {
        return SellerOrder::findOrFail(is_object($so) ? $so->id : $so);
    }

    /** Payload of the last parcel creation logged for this seller_order. */
    protected function sentPayload(object|int $so): ?array
    {
        $raw = DB::table('intigo_logs')->where('seller_order_id', is_object($so) ? $so->id : $so)
            ->where('endpoint', '/parcels/by-name')->orderByDesc('id')->value('request');
        return $raw ? json_decode($raw, true) : null;
    }

    /** Signed webhook, exactly as Intigo sends it. */
    protected function webhook(string $nid, int $status, ?string $timestamp = null, ?string $secret = null, ?int $old = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode([
            'event'      => 'parcel.status_changed',
            'event_type' => 'status',
            'timestamp'  => $timestamp ?? now()->format('Y-m-d\TH:i:s.u') . '+01:00',
            'data'       => ['nid' => $nid, 'old_status' => $old, 'new_status' => $status, 'status_label' => "Label {$status}"],
        ], JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/api/webhooks/intigo', [], [], [], [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_ACCEPT'              => 'application/json',
            'HTTP_X_WEBHOOK_EVENT'     => 'parcel.status_changed',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) now()->timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, $secret ?? self::SECRET),
        ], $body);
    }

    /** A few Intigo regions, as intigo:sync-regions stores them. */
    protected function seedRegions(): void
    {
        DB::table('intigo_cities')->insertOrIgnore([
            ['id' => 23, 'name' => 'Tunis', 'name_ar' => 'تونس', 'wilaya' => 'Tunis', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'name' => 'Kef', 'name_ar' => 'الكاف', 'wilaya' => 'Le Kef', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('intigo_districts')->insertOrIgnore([
            ['id' => 235, 'city_id' => 23, 'name' => 'Bab Bhar', 'name_ar' => 'باب بحر', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 237, 'city_id' => 23, 'name' => 'Carthage', 'name_ar' => 'قرطاج', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 901, 'city_id' => 11, 'name' => 'Kef Ouest', 'name_ar' => 'الكاف الغربية', 'created_at' => now(), 'updated_at' => now()],
        ]);
        IntigoRegions::flush();
    }
}
