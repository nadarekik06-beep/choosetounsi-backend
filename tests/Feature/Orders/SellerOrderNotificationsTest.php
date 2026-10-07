<?php

namespace Tests\Feature\Orders;

use App\Helpers\PlatformUser;
use App\Http\Controllers\Api\Client\PaymentController;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\Orders\NewSellerOrderNotification;
use App\Notifications\Orders\SellerOrderCancelledNotification;
use App\Notifications\Orders\SellerOrderConfirmedNotification;
use App\Notifications\Orders\SellerPickupReminderNotification;
use App\Services\Orders\SellerOrderNotifier;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Seller e-mail + bell notifications for their sub-orders: new order,
 * confirmed by the admin, cancelled, pickup reminder.
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Orders/SellerOrderNotificationsTest.php
 */
class SellerOrderNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    private const ADDRESS = [
        'recipient_name' => 'سارة بن علي',
        'phone'          => '22 123 456',
        'wilaya'         => 'Ben Arous',
        'delegation'     => 'El Mourouj',
        'address'        => '12 rue de la Liberté, Résidence Yasmine',
        'postal_code'    => '2074',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.shipping_cost' => 8.0]);

        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();

        $this->runAfterCommitCallbacksInsideTheTestTransaction();
    }

    /**
     * DatabaseTransactions wraps each test in a transaction that never commits,
     * so Laravel 8 would hold every DB::afterCommit() callback forever. This
     * manager ignores the test's own transaction and runs the callbacks when
     * the application's transaction commits (rolled back → dropped), exactly
     * as in production.
     */
    private function runAfterCommitCallbacksInsideTheTestTransaction(): void
    {
        $manager = new class extends DatabaseTransactionsManager {
            public function committedTo(string $connection, int $level): void
            {
                [$done, $open] = $this->transactions->partition(
                    fn($t) => $t->connection == $connection && $t->level > $level
                );
                $this->transactions = $open->values();
                $done->each->executeCallbacks();
            }
        };

        $this->app->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
        Event::listen(TransactionCommitted::class, fn($e) => $manager->committedTo($e->connectionName, $e->connection->transactionLevel()));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * No seller (or admin) notification went out. The buyer's own notifications
     * (App\Notifications\Buyer\BuyerNotification, see BuyerNotificationsTest) may.
     */
    private function assertNoSellerNotification(): void
    {
        $fake = Notification::getFacadeRoot();
        $prop = (new \ReflectionClass($fake))->getProperty('notifications');
        $prop->setAccessible(true);

        $sent = [];
        foreach ($prop->getValue($fake) as $byId) {
            foreach ($byId as $byClass) {
                foreach (array_keys($byClass) as $class) {
                    if (!is_subclass_of($class, \App\Notifications\Buyer\BuyerNotification::class)) $sent[] = $class;
                }
            }
        }
        $this->assertSame([], $sent, 'no seller notification expected');
    }

    private function makeUser(string $role, string $locale = 'fr'): User
    {
        return $this->withCompleteProfile(User::create([
            'name'      => ucfirst($role) . ' ' . Str::random(5),
            'email'     => $role . '_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
            'locale'    => $locale,
        ]));
    }

    private function makeSeller(string $locale = 'fr', bool $completePickup = true): User
    {
        $seller = $this->makeUser('seller', $locale);
        SellerApplication::create([
            'user_id'              => $seller->id,
            'full_name'            => 'Mohamed Trabelsi',
            'phone_number'         => '55111222',
            'business_name'        => 'Atelier ' . Str::random(4),
            'business_category'    => 'crafts',
            'business_description' => 'Test shop',
            'wilaya'               => 'Sfax',
            'city'                 => 'Sakiet Ezzit',
            'pickup_address'       => $completePickup ? 'Route de Tunis km 5' : null,
            'pickup_postal_code'   => $completePickup ? '3021' : null,
            'status'               => 'approved',
        ]);
        return $seller;
    }

    private function makeProduct(User $seller, float $price = 40): Product
    {
        $name = 'Notif Product ' . Str::random(6);
        return Product::create([
            'seller_id'   => $seller->id,
            'name'        => $name,
            'slug'        => Str::slug($name),
            'price'       => $price,
            'stock'       => 50,
            'is_approved' => true,
            'is_active'   => true,
        ]);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    private function checkout(array $products, string $method = 'cod'): Order
    {
        $customer = $this->makeUser('client');
        foreach ($products as $product) {
            Cart::create(['user_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 2]);
        }
        $res = $this->as($customer)
            ->postJson('/api/checkout', self::ADDRESS + ['payment_method' => $method])
            ->assertCreated();

        return Order::findOrFail($res->json('order_id'));
    }

    private function subOrderOf(Order $order, User $seller): SellerOrder
    {
        return SellerOrder::where('order_id', $order->id)->where('seller_id', $seller->id)->firstOrFail();
    }

    private function admin(): self
    {
        return $this->as($this->makeUser('admin'));
    }

    /** The e-mail (subject + HTML) and bell payload of a captured notification. */
    private function render($notification, User $seller): array
    {
        $mail = $notification->toMail($seller);
        return ['subject' => $mail->subject, 'html' => (string) $mail->render(), 'db' => $notification->toDatabase($seller)];
    }

    private function withLocale(string $locale, \Closure $fn)
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try { return $fn(); } finally { app()->setLocale($previous); }
    }

    // ── New order ─────────────────────────────────────────────────────────────

    public function test_cod_order_notifies_each_seller_about_their_own_sub_order_only(): void
    {
        Notification::fake();
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $order   = $this->checkout([$this->makeProduct($sellerA), $this->makeProduct($sellerB), $this->makeProduct($sellerA)]);

        $soA = $this->subOrderOf($order, $sellerA);
        $soB = $this->subOrderOf($order, $sellerB);

        Notification::assertSentToTimes($sellerA, NewSellerOrderNotification::class, 1);
        Notification::assertSentToTimes($sellerB, NewSellerOrderNotification::class, 1);
        Notification::assertSentTo($sellerA, NewSellerOrderNotification::class, fn($n) => $n->sellerOrder->is($soA));
        Notification::assertSentTo($sellerB, NewSellerOrderNotification::class, fn($n) => $n->sellerOrder->is($soB));
        Notification::assertNotSentTo($order->user, NewSellerOrderNotification::class);

        // One notification per sub-order, covering all of the seller's items.
        $mail = $this->withLocale('en', fn() => $this->render(Notification::sent($sellerA, NewSellerOrderNotification::class)->first(), $sellerA));
        $this->assertStringContainsString('— 4 items', $mail['subject']);
    }

    public function test_wallet_order_notifies_right_away(): void
    {
        Notification::fake();
        $seller   = $this->makeSeller();
        $customer = $this->makeUser('client');
        $customer->forceFill(['wallet_balance' => 1000])->save();
        Cart::create(['user_id' => $customer->id, 'product_id' => $this->makeProduct($seller)->id, 'quantity' => 1]);

        $this->as($customer)->postJson('/api/checkout', self::ADDRESS + ['payment_method' => 'wallet'])->assertCreated();

        Notification::assertSentToTimes($seller, NewSellerOrderNotification::class, 1);
    }

    public function test_card_order_notifies_only_once_the_payment_succeeds(): void
    {
        Notification::fake();
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)], 'card');
        $this->assertNoSellerNotification();

        $order->forceFill(['stripe_payment_intent_id' => 'pi_test_' . Str::random(8)])->save();
        $webhook = (new \ReflectionClass(PaymentController::class));
        $failure = $webhook->getMethod('handlePaymentFailure');
        $success = $webhook->getMethod('handlePaymentSuccess');
        $failure->setAccessible(true);
        $success->setAccessible(true);
        $controller = app(PaymentController::class);

        $failure->invoke($controller, $order->stripe_payment_intent_id);
        $this->assertNoSellerNotification();

        $success->invoke($controller, $order->stripe_payment_intent_id);
        $success->invoke($controller, $order->stripe_payment_intent_id); // Stripe retries webhooks
        Notification::assertSentToTimes($seller, NewSellerOrderNotification::class, 1);
    }

    public function test_d17_order_waits_for_the_admin_to_confirm_the_payment(): void
    {
        Notification::fake();
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)], 'd17');
        $this->assertNoSellerNotification();

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-payment", ['d17_reference' => 'D17-123'])->assertOk();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/payment-status", ['payment_status' => 'paid'])->assertOk();

        Notification::assertSentToTimes($seller, NewSellerOrderNotification::class, 1);
    }

    public function test_unpaid_card_order_cancelled_as_abandoned_notifies_nobody(): void
    {
        Notification::fake();
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)], 'card');
        $order->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('orders:cancel-abandoned-card')->assertExitCode(0);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertNoSellerNotification();
    }

    public function test_cod_payment_collected_after_delivery_does_not_announce_the_order_again(): void
    {
        $seller = $this->makeSeller();
        Notification::fake();
        $order = $this->checkout([$this->makeProduct($seller)]);
        DB::table('seller_order_notifications')->delete(); // e.g. an order placed before this feature
        Notification::fake();

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-payment")->assertOk();

        $this->assertNoSellerNotification();
    }

    public function test_a_rolled_back_transaction_sends_nothing(): void
    {
        Notification::fake();
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)]);
        DB::table('seller_order_notifications')->delete();
        Notification::fake();

        try {
            DB::transaction(function () use ($order) {
                app(SellerOrderNotifier::class)->orderPlaced($order);
                throw new \RuntimeException('checkout failed after the sub-orders were created');
            });
        } catch (\RuntimeException $e) {
        }

        $this->assertNoSellerNotification();
        $this->assertSame(0, DB::table('seller_order_notifications')->count(), 'the claim rolls back with the order');
    }

    /** Money words and figures that only the "confirmed" e-mail may show. */
    private function assertNoEarnings(string $content, SellerOrder $so): void
    {
        foreach (['commission', 'Commission', 'net earnings', 'Net earnings', 'payout', 'Items total', 'subscription'] as $word) {
            $this->assertStringNotContainsString($word, $content);
        }
        $this->assertStringNotContainsString(number_format((float) $so->commission_amount, 3, '.', ' '), $content);
        $this->assertStringNotContainsString(number_format((float) $so->seller_net_amount, 3, '.', ' '), $content);
    }

    public function test_new_order_email_lists_the_products_and_no_earnings(): void
    {
        config(['app.url' => 'https://api.choosetounsi.test', 'filesystems.disks.public.url' => 'https://api.choosetounsi.test/storage']);
        $seller  = $this->makeSeller('en');
        $product = $this->makeProduct($seller, 25);
        \App\Models\ProductImage::create(['product_id' => $product->id, 'image_path' => 'products/lamp.jpg', 'is_primary' => true, 'order' => 0]);
        $plain   = $this->makeProduct($seller, 10);   // no photo
        Notification::fake();
        $order = $this->checkout([$product, $plain]);
        $so    = $this->subOrderOf($order, $seller);
        $so->items()->where('product_id', $product->id)->update(['variant_label' => 'Red / M']);

        $notification = Notification::sent($seller, NewSellerOrderNotification::class)->first();
        [$mail, $text] = $this->withLocale('en', function () use ($notification, $seller) {
            $mail = $notification->toMail($seller);
            return [$this->render($notification, $seller), view($mail->view[1], $mail->viewData)->render()];
        });
        $ref = $order->order_number . '-S' . $so->id;

        $this->assertSame("New order {$ref} — 4 items", $mail['subject']);
        foreach ([$mail['html'], $text] as $content) {
            $this->assertStringContainsString($ref, $content);
            $this->assertStringContainsString($product->name, $content);
            $this->assertStringContainsString('Red / M', $content);
            $this->assertStringContainsString('25.000 DT', $content);     // unit price
            $this->assertStringContainsString('50.000 DT', $content);     // line total (2 × 25)
            $this->assertStringContainsString('20.000 DT', $content);     // 2 × 10
            $this->assertStringContainsString('Ordered on', $content);
            $this->assertStringContainsString($order->created_at->copy()->timezone('Africa/Tunis')->format('Y'), $content);
            $this->assertStringContainsString('/seller/orders?order=' . $so->id, $content);
            $this->assertNoEarnings($content, $so);
        }
        $this->assertStringContainsString("Awaiting confirmation by Choose&#039;Tounsi — don&#039;t ship anything yet.", $mail['html']);
        $this->assertStringContainsString('<img src="https://api.choosetounsi.test/storage/products/lamp.jpg"', $mail['html']);
        $this->assertSame(1, substr_count($mail['html'], '<img '), 'no broken image for the product without a photo');

        // The bell entry is just as plain.
        $this->assertSame('4 items · awaiting confirmation', $mail['db']['body']);
        $this->assertNoEarnings(json_encode($mail['db']), $so);
        $this->assertStringNotContainsString(' DT', json_encode($mail['db']));
    }

    // ── Confirmed ─────────────────────────────────────────────────────────────

    public function test_admin_confirmation_notifies_each_seller_once_even_on_repeated_clicks(): void
    {
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $order   = $this->checkout([$this->makeProduct($sellerA), $this->makeProduct($sellerB)]);
        Notification::fake();

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertStatus(422);
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk(); // status re-save
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        $soA = $this->subOrderOf($order, $sellerA);
        Notification::assertSentToTimes($sellerA, SellerOrderConfirmedNotification::class, 1);
        Notification::assertSentToTimes($sellerB, SellerOrderConfirmedNotification::class, 1);
        Notification::assertSentTo($sellerA, SellerOrderConfirmedNotification::class, fn($n) => $n->sellerOrder->is($soA));
    }

    public function test_confirmed_email_has_reference_items_pickup_and_tips(): void
    {
        $seller = $this->makeSeller('en', completePickup: false);
        $order  = $this->checkout([$this->makeProduct($seller, 25)]);
        Notification::fake();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();

        $notification = Notification::sent($seller, SellerOrderConfirmedNotification::class)->first();
        $mail = $this->withLocale('en', fn() => $this->render($notification, $seller));
        $ref  = $order->order_number . '-S' . $this->subOrderOf($order, $seller)->id;

        $this->assertSame("Order {$ref} confirmed — prepare the package", $mail['subject']);
        $this->assertStringContainsString($ref, $mail['html']);
        $this->assertStringContainsString('Write this reference on the package', $mail['html']);
        $this->assertStringContainsString('Packing tips', $mail['html']);
        $this->assertStringContainsString('Your pickup address is incomplete', $mail['html']);
        $this->assertStringContainsString('/seller/settings#pickup', $mail['html']);
        $this->assertStringContainsString('/seller/orders?order=' . $notification->sellerOrder->id, $mail['html']);
        $this->assertStringContainsString('50.000 DT', $mail['html']);           // 2 × 25 DT
        $this->assertStringContainsString('Your net earnings', $mail['html']);
        $this->assertSame('/seller/orders?order=' . $notification->sellerOrder->id, $mail['db']['link']);
    }

    // ── Cancelled ─────────────────────────────────────────────────────────────

    /**
     * The "confirmed" e-mail is frozen: compared byte for byte (HTML + text,
     * EN + AR) with golden files. Regenerate only on purpose:
     *   $env:UPDATE_SNAPSHOTS=1; php vendor/bin/phpunit --filter confirmed_email_is_unchanged
     */
    public function test_confirmed_email_is_unchanged(): void
    {
        config(['app.frontend_url' => 'https://choosetounsi.test', 'mail.from.address' => 'hello@choosetounsi.test']);
        $seller  = $this->makeSeller('en');
        $seller->sellerApplication()->update(['business_name' => 'Atelier Snapshot']);
        $product = $this->makeProduct($seller, 25);
        $product->update(['name' => 'Snapshot Lamp']);
        $order = $this->checkout([$product]);
        Notification::fake();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();

        $notification = Notification::sent($seller, SellerOrderConfirmedNotification::class)->first();
        $so  = $notification->sellerOrder;
        $ref = $order->order_number . '-S' . $so->id;
        // Line endings normalized: git may check views and snapshots out as CRLF on Windows.
        $normalize = fn(string $s) => str_replace(["\r\n", $ref, 'order=' . $so->id, date('Y')], ["\n", '{REF}', 'order={ID}', '{YEAR}'], $s);

        foreach (['en', 'ar'] as $locale) {
            [$html, $text] = $this->withLocale($locale, function () use ($notification, $seller) {
                $mail = $notification->toMail($seller);
                return [(string) $mail->render(), view($mail->view[1], $mail->viewData)->render()];
            });
            foreach (['html' => $html, 'txt' => $text] as $ext => $content) {
                $file = __DIR__ . "/snapshots/confirmed-{$locale}.{$ext}";
                if (getenv('UPDATE_SNAPSHOTS')) {
                    @mkdir(dirname($file));
                    file_put_contents($file, $normalize($content));
                }
                $this->assertFileExists($file);
                $this->assertSame(str_replace("\r\n", "\n", file_get_contents($file)), $normalize($content), "confirmed-{$locale}.{$ext} changed");
            }
        }
    }

    public function test_cancellation_notifies_only_sellers_who_were_told_about_the_order(): void
    {
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $order   = $this->checkout([$this->makeProduct($sellerA), $this->makeProduct($sellerB)]);
        // Seller B's sub-order predates the feature: never notified.
        DB::table('seller_order_notifications')->where('seller_order_id', $this->subOrderOf($order, $sellerB)->id)->delete();
        Notification::fake();

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'cancelled'])->assertOk();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();

        Notification::assertSentToTimes($sellerA, SellerOrderCancelledNotification::class, 1);
        Notification::assertNotSentTo($sellerB, SellerOrderCancelledNotification::class);
    }

    public function test_cancelled_email_is_minimal_without_amounts_or_reason(): void
    {
        $seller  = $this->makeSeller('en');
        $product = $this->makeProduct($seller, 25);
        $order   = $this->checkout([$product]);
        $so      = $this->subOrderOf($order, $seller);
        $so->items()->update(['variant_label' => 'Blue / L']);
        Notification::fake();

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", [
            'action' => 'cancelled', 'admin_note' => 'Customer unreachable after 3 calls',
        ])->assertOk();

        $notification = Notification::sent($seller, SellerOrderCancelledNotification::class)->first();
        [$mail, $text] = $this->withLocale('en', function () use ($notification, $seller) {
            $mail = $notification->toMail($seller);
            return [$this->render($notification, $seller), view($mail->view[1], $mail->viewData)->render()];
        });
        $ref = $order->order_number . '-S' . $so->id;

        $this->assertSame("Order {$ref} cancelled", $mail['subject']);
        foreach ([$mail['html'], $text, json_encode($mail['db'], JSON_UNESCAPED_UNICODE)] as $content) {
            $this->assertStringNotContainsString('Customer unreachable', $content);   // internal note stays internal
            $this->assertStringNotContainsString(' DT', $content);
            $this->assertDoesNotMatchRegularExpression('/\d+\.\d{3}/', $content);    // no amount at all
            $this->assertNoEarnings($content, $so);
        }
        foreach ([$mail['html'], $text] as $content) {
            $this->assertStringContainsString($ref, $content);
            $this->assertStringContainsString($product->name, $content);
            $this->assertStringContainsString('Blue / L', $content);
            $this->assertStringContainsString('× 2', html_entity_decode(str_replace('&nbsp;', ' ', $content)));
            $this->assertStringContainsString('This order has been cancelled. Please do not prepare or ship it.', $content);
            $this->assertStringContainsString('/seller/orders?order=' . $so->id, $content);
        }
    }

    public function test_cancelling_an_unpaid_card_order_notifies_nobody(): void
    {
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)], 'card');
        Notification::fake();

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'cancelled'])->assertOk();

        $this->assertNoSellerNotification();
    }

    // ── Language & privacy ────────────────────────────────────────────────────

    public function test_each_seller_is_notified_in_their_language_and_arabic_is_rtl(): void
    {
        Notification::fake();
        $arabic = $this->makeSeller('ar');
        $french = $this->makeSeller('fr');
        $this->checkout([$this->makeProduct($arabic), $this->makeProduct($french)]);

        Notification::assertSentTo($arabic, NewSellerOrderNotification::class, fn($n, $c, $nb, $locale) => $locale === 'ar');
        Notification::assertSentTo($french, NewSellerOrderNotification::class, fn($n, $c, $nb, $locale) => $locale === 'fr');

        $ar = $this->withLocale('ar', fn() => $this->render(Notification::sent($arabic, NewSellerOrderNotification::class)->first(), $arabic));
        $this->assertStringStartsWith('طلب جديد', $ar['subject']);
        $this->assertStringContainsString('dir="rtl"', $ar['html']);
        $this->assertStringContainsString('لديك طلب جديد', $ar['html']);

        $fr = $this->withLocale('fr', fn() => $this->render(Notification::sent($french, NewSellerOrderNotification::class)->first(), $french));
        $this->assertStringStartsWith('Nouvelle commande', $fr['subject']);
        $this->assertStringContainsString('dir="ltr"', $fr['html']);
    }

    public function test_seller_never_sees_the_buyers_phone_or_address(): void
    {
        $seller = $this->makeSeller('fr');
        $order  = $this->checkout([$this->makeProduct($seller)]);
        Notification::fake();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();

        $notification = Notification::sent($seller, SellerOrderConfirmedNotification::class)->first();
        $mail = $this->withLocale('fr', fn() => $this->render($notification, $seller));
        $everything = $mail['html'] . json_encode($mail['db'], JSON_UNESCAPED_UNICODE)
            . $notification->toMail($seller)->render();

        foreach (['22123456', '22 123 456', '12 rue de la Liberté', 'Résidence Yasmine', 'El Mourouj', '2074', 'بن علي'] as $private) {
            $this->assertStringNotContainsString($private, $everything);
        }
        $this->assertStringContainsString('سارة · Ben Arous', html_entity_decode($mail['html']));  // first name + wilaya only
    }

    // ── Channels, failures, bell API ──────────────────────────────────────────

    public function test_bell_entry_is_saved_even_when_the_email_fails(): void
    {
        $seller = $this->makeSeller();
        Mail::extend('failing', fn() => new class extends \Illuminate\Mail\Transport\Transport {
            public function send(\Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
            {
                throw new \RuntimeException('SMTP down');
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        Log::spy();

        $this->checkout([$this->makeProduct($seller)]);   // sync queue in tests: runs right away

        $this->assertSame(1, $seller->notifications()->count());
        Log::shouldHaveReceived('error')->withArgs(fn($msg) => str_contains($msg, 'SMTP down'))->atLeast()->once();

        // The seller dashboard bell reads it from the existing API.
        $res = $this->as($seller)->getJson('/api/notifications?unread=1')->assertOk();
        $this->assertSame('seller_order', $res->json('data.0.data.type'));
        $this->assertStringStartsWith('/seller/orders?order=', $res->json('data.0.data.link'));
        $this->as($seller)->getJson('/api/notifications/unread-count')->assertJson(['count' => 1]);
    }

    public function test_email_is_actually_sent_with_html_and_text_parts(): void
    {
        $seller = $this->makeSeller('en');
        config(['mail.default' => 'array']);

        $order = $this->checkout([$this->makeProduct($seller)]);

        // The seller's "new order" + the buyer's receipt (BuyerNotificationsTest)
        $messages = app('mailer')->getSwiftMailer()->getTransport()->messages();
        $this->assertCount(2, $messages);
        $this->assertCount(1, $messages->filter(fn($m) => array_keys($m->getTo()) === [$order->user->email]));
        $message = $messages->first(fn($m) => array_keys($m->getTo()) === [$seller->email]);
        $this->assertNotNull($message);
        $this->assertStringStartsWith('New order ', $message->getSubject());
        $this->assertStringContainsString('<html', $message->getBody());
        $this->assertNotEmpty(collect($message->getChildren())->filter(fn($p) => $p->getContentType() === 'text/plain'));
    }

    // ── Pickup reminder ───────────────────────────────────────────────────────

    public function test_pickup_reminder_is_off_by_default_and_sent_once_when_enabled(): void
    {
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        DB::table('seller_order_notifications')->where('event', 'confirmed')->update(['created_at' => now()->subHours(25)]);
        Notification::fake();

        $this->artisan('orders:remind-seller-pickup')->assertExitCode(0);
        $this->assertNoSellerNotification();

        config(['seller_notifications.pickup_reminder.enabled' => true]);
        $this->artisan('orders:remind-seller-pickup')->assertExitCode(0);
        $this->artisan('orders:remind-seller-pickup')->assertExitCode(0);

        Notification::assertSentToTimes($seller, SellerPickupReminderNotification::class, 1);
    }

    public function test_no_pickup_reminder_once_the_sub_order_is_ready(): void
    {
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        DB::table('seller_order_notifications')->where('event', 'confirmed')->update(['created_at' => now()->subHours(25)]);
        $this->subOrderOf($order, $seller)->update(['status' => 'completed']);
        Notification::fake();
        config(['seller_notifications.pickup_reminder.enabled' => true]);

        $this->artisan('orders:remind-seller-pickup')->assertExitCode(0);

        $this->assertNoSellerNotification();
    }
}
