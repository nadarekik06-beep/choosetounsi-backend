<?php

namespace Tests\Feature\Notifications;

use App\Helpers\PlatformUser;
use App\Models\Cart;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewPrompt;
use App\Models\SellerApplication;
use App\Models\SellerOrder;
use App\Models\User;
use App\Notifications\Buyer\AccountSecurityNotification;
use App\Notifications\Buyer\ComplaintNotification;
use App\Notifications\Buyer\FavoriteProductNotification;
use App\Notifications\Buyer\OrderPlacedNotification;
use App\Notifications\Buyer\OrderStatusNotification;
use App\Notifications\Buyer\PaymentNotification;
use App\Notifications\ReviewPromptNotification;
use App\Notifications\Support\NotificationPreferences;
use App\Notifications\Support\Payload;
use App\Services\Notifications\BuyerNotifier;
use App\Services\Orders\BuyerOrderNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buyer notifications: order lifecycle, payload contract, preferences, dedupe,
 * promotions cap, seller-as-buyer audience, review reminders, legacy rows.
 *
 * Run only this file:  php artisan test tests/Feature/Notifications/BuyerNotificationsTest.php
 */
class BuyerNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    private const ADDRESS = [
        'recipient_name' => 'Sara Ben Ali',
        'phone'          => '22 123 456',
        'wilaya'         => 'Ben Arous',
        'delegation'     => 'El Mourouj',
        'address'        => '12 rue de la Liberté',
        'postal_code'    => '2074',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.shipping_cost' => 8.0, 'mail.default' => 'array']);

        if (!DB::table('users')->where('id', 1)->exists()) {
            DB::table('users')->insert([
                'id' => 1, 'name' => "CHOOSE'Tounsi", 'email' => 'platform-' . Str::random(6) . '@test.local',
                'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        PlatformUser::reset();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeUser(string $role = 'client'): User
    {
        return $this->withCompleteProfile(User::create([
            'name'      => ucfirst($role) . ' ' . Str::random(5),
            'email'     => 'buyernotif_' . $role . '_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
            'locale'    => 'fr',
        ]));
    }

    private function makeSeller(string $shop = null): User
    {
        $seller = $this->makeUser('seller');
        $seller->forceFill(['first_name' => 'Sami', 'last_name' => 'Seller', 'phone' => '22123457'])->save();
        SellerApplication::create([
            'user_id' => $seller->id, 'full_name' => 'Sami Seller', 'phone_number' => '55111222',
            'business_name' => $shop ?? 'Boutique ' . Str::random(4), 'business_category' => 'crafts',
            'business_description' => 'Test shop', 'wilaya' => 'Sfax', 'city' => 'Sfax',
            'pickup_address' => 'Route de Tunis km 5', 'pickup_postal_code' => '3021', 'status' => 'approved',
        ]);
        return $seller;
    }

    private function makeProduct(User $seller, float $price = 40, int $stock = 50): Product
    {
        $name = 'Buyer Notif ' . Str::random(6);
        return Product::create([
            'seller_id' => $seller->id, 'name' => $name, 'slug' => Str::slug($name),
            'price' => $price, 'stock' => $stock, 'is_approved' => true, 'is_active' => true,
        ]);
    }

    private function as(User $user): self
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken]);
    }

    private function checkout(array $products, string $method = 'cod', ?User $buyer = null): Order
    {
        $buyer ??= $this->makeUser();
        foreach ($products as $product) {
            Cart::create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        }
        $res = $this->as($buyer)->postJson('/api/checkout', self::ADDRESS + ['payment_method' => $method])->assertCreated();
        return Order::findOrFail($res->json('order_id'));
    }

    private function subOrder(Order $order, User $seller): SellerOrder
    {
        return SellerOrder::where('order_id', $order->id)->where('seller_id', $seller->id)->firstOrFail();
    }

    private function admin(): self
    {
        return $this->as($this->makeUser('admin'));
    }

    /** Notifications sent to $user of $class (Notification::fake). */
    private function sent(User $user, string $class)
    {
        return Notification::sent($user, $class);
    }

    // ── Order lifecycle ───────────────────────────────────────────────────────

    public function test_order_lifecycle_notifies_the_buyer_once_per_step(): void
    {
        Notification::fake();
        $seller = $this->makeSeller('Atelier Jasmin');
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $so     = $this->subOrder($order, $seller);

        Notification::assertSentToTimes($order->user, OrderPlacedNotification::class, 1);

        // Admin confirms (twice: double click)
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();
        $this->admin()->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'confirmed'])->assertOk();

        // Seller packs, ships, delivers — and clicks "delivered" twice
        foreach (['completed', 'out_for_delivery', 'delivered', 'delivered'] as $status) {
            $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => $status])->assertOk();
        }

        $steps = $this->sent($order->user, OrderStatusNotification::class)->pluck('event')->all();
        $this->assertSame(['confirmed', 'packed', 'shipped', 'delivered'], $steps);

        $shipped = $this->sent($order->user, OrderStatusNotification::class)->firstWhere('event', 'shipped');
        $db = $shipped->toDatabase($order->user);
        $this->assertSame('Votre colis de Atelier Jasmin est en route', $db['title']);
        $this->assertSame('/orders?order=' . $order->id, $db['link']);
        $this->assertSame('orders', $db['category']);
        $this->assertSame('buyer', $db['audience']);

        // Delivered: review prompts exist for the /orders popup
        $this->assertSame(1, ReviewPrompt::where('user_id', $order->user_id)->count());
        // The seller never gets buyer notifications for this order
        Notification::assertNotSentTo($seller, OrderStatusNotification::class);
    }

    public function test_multi_shop_confirmation_is_one_notification_naming_every_shop(): void
    {
        Notification::fake();
        $a = $this->makeSeller('Shop Alpha');
        $b = $this->makeSeller('Shop Beta');
        $order = $this->checkout([$this->makeProduct($a), $this->makeProduct($b)]);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-order", ['action' => 'confirmed'])->assertOk();

        $sent = $this->sent($order->user, OrderStatusNotification::class);
        $this->assertCount(1, $sent);
        $this->assertCount(2, $sent->first()->sellerOrderIds);
        $body = $sent->first()->toDatabase($order->user)['body'];
        $this->assertStringContainsString('Shop Alpha et Shop Beta', $body);

        // Then each shop ships on its own: one message per shop, naming it
        $this->as($a)->patchJson('/api/seller/orders/' . $this->subOrder($order, $a)->id . '/status', ['status' => 'out_for_delivery'])->assertOk();
        $shipped = $this->sent($order->user, OrderStatusNotification::class)->where('event', 'shipped');
        $this->assertCount(1, $shipped);
        $this->assertStringContainsString('Shop Alpha', $shipped->first()->toDatabase($order->user)['title']);
        $this->assertStringNotContainsString('Shop Beta', $shipped->first()->toDatabase($order->user)['title']);
    }

    public function test_cancellation_by_the_seller_names_the_shop_and_says_nothing_is_charged(): void
    {
        Notification::fake();
        $seller = $this->makeSeller('Bayti Home');
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $so     = $this->subOrder($order, $seller);

        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'cancelled'])->assertOk();

        $n = $this->sent($order->user, OrderStatusNotification::class)->firstWhere('event', 'cancelled');
        $this->assertNotNull($n);
        $this->assertSame('seller', $n->reason);
        $this->assertSame('La boutique Bayti Home a annulé votre commande.', $n->toDatabase($order->user)['body']);
        $html = (string) $n->toMail($order->user)->render();
        $this->assertStringContainsString('Aucun montant ne vous sera facturé', $html);
    }

    public function test_d17_order_says_payment_pending_and_receipt_once_paid(): void
    {
        Notification::fake();
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)], 'd17');

        Notification::assertSentTo($order->user, PaymentNotification::class, fn ($n) => $n->event === 'pending');
        Notification::assertNotSentTo($order->user, OrderPlacedNotification::class);

        $this->admin()->patchJson("/api/admin/orders/{$order->id}/confirm-payment", ['d17_reference' => 'D17-1'])->assertOk();
        Notification::assertSentToTimes($order->user, OrderPlacedNotification::class, 1);
    }

    public function test_refused_card_payment_is_told_to_the_buyer(): void
    {
        Notification::fake();
        $order = $this->checkout([$this->makeProduct($this->makeSeller())], 'card');
        $order->forceFill(['stripe_payment_intent_id' => 'pi_test_' . Str::random(8)])->save();

        $method = (new \ReflectionClass(\App\Http\Controllers\Api\Client\PaymentController::class))->getMethod('handlePaymentFailure');
        $method->setAccessible(true);
        $method->invoke(app(\App\Http\Controllers\Api\Client\PaymentController::class), $order->stripe_payment_intent_id, true);

        Notification::assertSentToTimes($order->user, PaymentNotification::class, 1);
    }

    // ── Payload contract ──────────────────────────────────────────────────────

    public function test_stored_rows_follow_the_contract(): void
    {
        $order = $this->checkout([$this->makeProduct($this->makeSeller())]);   // real channels (sync queue)

        $row = DB::table('notifications')->where('notifiable_id', $order->user_id)
            ->where('type', OrderPlacedNotification::class)->first();
        $this->assertNotNull($row);
        $this->assertSame('buyer', $row->audience);
        $this->assertSame('orders', $row->category);

        $data = json_decode($row->data, true);
        $this->assertSame(Payload::KEYS, array_keys($data));
        $this->assertSame('/orders?order=' . $order->id, $data['link']);
        $this->assertSame($order->id, $data['data']['order_id']);
        $this->assertArrayNotHasKey('message', $data);

        // The receipt e-mail went out too, with the total
        $mails = app('mailer')->getSwiftMailer()->getTransport()->messages()
            ->filter(fn ($m) => array_keys($m->getTo()) === [$order->user->email]);
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Total', $mails->first()->getBody());
    }

    public function test_any_notification_is_normalized_by_the_database_channel(): void
    {
        $user = $this->makeUser();
        $user->notify(new class extends \Illuminate\Notifications\Notification {
            public function via($n) { return ['database']; }
            public function toArray($n) { return ['title' => 'Old', 'message' => 'legacy body', 'action_url' => '/orders', 'order_id' => 7]; }
        });

        $data = $user->notifications()->first()->data;
        $this->assertSame('legacy body', $data['body']);
        $this->assertSame('/orders', $data['link']);
        $this->assertSame(['order_id' => 7], $data['data']);
        $this->assertSame('buyer', $data['audience']);
    }

    // ── Preferences ───────────────────────────────────────────────────────────

    public function test_transactional_bell_cannot_be_turned_off_but_email_can(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['notification_preferences' => ['orders_in_app' => false, 'orders_email' => false, 'in_app_updates' => false]])->save();
        $order = $this->checkout([$this->makeProduct($this->makeSeller())], 'cod', $user);

        $channels = (new OrderPlacedNotification($order))->via($user->fresh());
        $this->assertSame(['database'], $channels);

        // The API refuses to store a locked channel
        $this->as($user)->putJson('/api/profile/notifications', ['orders_in_app' => false, 'reviews_in_app' => false])->assertOk();
        $prefs = $user->fresh()->notification_preferences;
        $this->assertFalse($prefs['reviews_in_app']);
        $settings = collect(NotificationPreferences::settings($user->fresh()))->keyBy('category');
        $this->assertTrue($settings['orders']['in_app']['locked']);
        $this->assertTrue($settings['orders']['in_app']['enabled']);
        $this->assertFalse($settings['reviews']['in_app']['enabled']);
        $this->assertTrue($settings['account']['email']['locked']);
    }

    public function test_reviews_and_promotions_respect_the_users_choice(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['notification_preferences' => ['reviews_in_app' => false, 'reviews_email' => false, 'promotions_in_app' => false]])->save();
        $user = $user->fresh();

        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller);
        $this->assertSame([], (new FavoriteProductNotification($product, 'back_in_stock'))->via($user));
        $this->assertFalse(app(BuyerNotifier::class)->send($user, new FavoriteProductNotification($product, 'back_in_stock')));

        // Promotions e-mail is opt-in only, with a verified address
        $this->assertFalse(NotificationPreferences::allows($user, 'promotions', 'email'));
        $user->forceFill(['marketing_emails_opt_in' => true, 'email_verified_at' => now()])->save();
        $this->assertTrue(NotificationPreferences::allows($user->fresh(), 'promotions', 'email'));

        // Account notifications are always on
        $this->assertSame(['database', 'mail'], (new AccountSecurityNotification('password_changed'))->via($user));
    }

    // ── Dedupe & promotions cap ───────────────────────────────────────────────

    public function test_dedupe_key_sends_once_and_promotions_are_capped_daily(): void
    {
        Notification::fake();
        $user   = $this->makeUser();
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)], 'cod', $user);

        $notifier = app(BuyerNotifier::class);
        $this->assertFalse($notifier->send($user, new OrderPlacedNotification($order)), 'already sent at checkout');
        Notification::assertSentToTimes($user, OrderPlacedNotification::class, 1);

        $this->assertTrue($notifier->send($user, new FavoriteProductNotification($this->makeProduct($seller), 'back_in_stock')));
        $this->assertFalse($notifier->send($user, new FavoriteProductNotification($this->makeProduct($seller), 'back_in_stock')));
        Notification::assertSentToTimes($user, FavoriteProductNotification::class, 1);
    }

    public function test_favourite_back_in_stock_and_price_drop_reach_the_buyers_who_favourited_it(): void
    {
        Notification::fake();
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller, 100, 0);
        $fan     = $this->makeUser();
        DB::table('favorites')->insert(['user_id' => $fan->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);

        $product->update(['stock' => 5]);
        Notification::assertSentTo($fan, FavoriteProductNotification::class, fn ($n) => $n->event === 'back_in_stock');

        $other = $this->makeUser();
        DB::table('favorites')->insert(['user_id' => $other->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);
        $product->update(['price' => 98]);   // -2 %: below the threshold
        Notification::assertNotSentTo($other, FavoriteProductNotification::class);
        $product->update(['price' => 80]);
        Notification::assertSentTo($other, FavoriteProductNotification::class, fn ($n) => $n->event === 'price_drop' && $n->newPrice == 80.0);
        Notification::assertNotSentTo($seller, FavoriteProductNotification::class);
    }

    // ── Sellers are buyers too ────────────────────────────────────────────────

    public function test_a_seller_buying_elsewhere_gets_buyer_notifications_in_the_buyer_audience_only(): void
    {
        $buyerSeller = $this->makeSeller('My Own Shop');
        $order = $this->checkout([$this->makeProduct($this->makeSeller())], 'cod', $buyerSeller);
        // A seller notification of their own, for the dashboard bell
        $buyerSeller->notify(new \App\Notifications\SubscriptionUpdatedNotification('reactivated', 'reactivated'));

        $client = $this->as($buyerSeller);
        $buyerRows  = $client->getJson('/api/notifications?audience=buyer')->assertOk()->json('data');
        $sellerRows = $client->getJson('/api/notifications?audience=seller')->assertOk()->json('data');

        $this->assertSame(['order_placed'], array_column(array_column($buyerRows, 'data'), 'type'));
        $this->assertNotContains('order_placed', array_column(array_column($sellerRows, 'data'), 'type'));
        $this->assertSame(1, $client->getJson('/api/notifications/unread-count?audience=buyer')->json('count'));
        $this->assertSame(1, $client->getJson('/api/notifications/unread-count?audience=seller')->json('count'));

        // Mark all read in the storefront: the dashboard badge is untouched
        $client->patchJson('/api/notifications/read-all?audience=buyer')->assertOk();
        $this->assertSame(0, $client->getJson('/api/notifications/unread-count?audience=buyer')->json('count'));
        $this->assertSame(1, $client->getJson('/api/notifications/unread-count?audience=seller')->json('count'));

        // Delete one
        $id = $buyerRows[0]['id'];
        $client->deleteJson("/api/notifications/{$id}")->assertOk();
        $this->assertSame(0, $client->getJson('/api/notifications?audience=buyer')->json('meta.total'));
    }

    public function test_a_seller_cannot_buy_their_own_product_with_buy_now(): void
    {
        $seller  = $this->makeSeller();
        $product = $this->makeProduct($seller);
        $this->as($seller)->postJson('/api/checkout/buy-now', self::ADDRESS + ['product_id' => $product->id, 'quantity' => 1])
            ->assertStatus(422);
        $this->assertSame(0, Order::where('user_id', $seller->id)->count());
    }

    // ── Complaints ────────────────────────────────────────────────────────────

    public function test_complaint_steps_reach_the_buyer(): void
    {
        Notification::fake();
        $seller = $this->makeSeller();
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $complaint = Complaint::create([
            'user_id' => $order->user_id, 'order_id' => $order->id, 'seller_id' => $seller->id,
            'complaint_type' => 'damaged', 'resolution_type' => 'return_refund',
            'description' => 'Arrived broken, please refund.', 'status' => Complaint::STATUS_PENDING,
        ]);

        $notifier = app(BuyerNotifier::class);
        $notifier->send($order->user, new ComplaintNotification($complaint, 'received'));
        $notifier->send($order->user, new ComplaintNotification($complaint, 'received'));   // retry

        $this->as($seller)->patchJson("/api/seller/complaints/{$complaint->id}/approve", ['seller_note' => 'Sorry about that'])->assertOk();

        // The decision is sent as ComplaintStatusChangedNotification (a ComplaintNotification)
        $all = $this->sent($order->user, ComplaintNotification::class)
            ->merge($this->sent($order->user, \App\Notifications\ComplaintStatusChangedNotification::class));
        $this->assertSame(['received', 'approved'], $all->pluck('event')->all());
        $approved = $all->firstWhere('event', 'approved');
        $this->assertStringContainsString('un livreur passera', $approved->toDatabase($order->user)['body']);
        $this->assertSame('/complaints?id=' . $complaint->id, $approved->toDatabase($order->user)['link']);
    }

    // ── Review reminders ──────────────────────────────────────────────────────

    public function test_review_reminder_goes_out_once_after_n_days_and_skips_reviewed_items(): void
    {
        config(['notifications.review_prompt_delay_days' => 3]);
        $seller = $this->makeSeller();
        $p1 = $this->makeProduct($seller);
        $p2 = $this->makeProduct($seller);
        $order = $this->checkout([$p1, $p2]);
        $so = $this->subOrder($order, $seller);
        $this->as($seller)->patchJson("/api/seller/orders/{$so->id}/status", ['status' => 'delivered'])->assertOk();
        $this->assertSame(2, ReviewPrompt::where('user_id', $order->user_id)->count());

        Notification::fake();
        $this->artisan('notifications:review-reminders')->assertExitCode(0);
        Notification::assertNotSentTo($order->user, ReviewPromptNotification::class);   // too early

        // One item already reviewed
        $item1 = $order->items()->where('product_id', $p1->id)->first();
        Review::create(['user_id' => $order->user_id, 'product_id' => $p1->id, 'order_item_id' => $item1->id,
            'seller_id' => $seller->id, 'rating' => 5, 'body' => 'Great product, thanks', 'status' => 'approved']);

        $this->travel(3)->days();
        $this->travel(1)->hours();
        $this->artisan('notifications:review-reminders')->assertExitCode(0);
        $this->artisan('notifications:review-reminders')->assertExitCode(0);

        Notification::assertSentToTimes($order->user, ReviewPromptNotification::class, 1);
        $n = $this->sent($order->user, ReviewPromptNotification::class)->first();
        $item2 = $order->items()->where('product_id', $p2->id)->first();
        $this->assertSame([$item2->id], $n->orderItemIds);
        $this->assertSame('reviews', $n->toDatabase($order->user)['category']);
    }

    // ── Legacy rows ───────────────────────────────────────────────────────────

    public function test_normalize_command_converts_legacy_rows(): void
    {
        $user = $this->makeUser();
        $id   = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\\Notifications\\RefundCompletedNotification',
            'notifiable_type' => User::class, 'notifiable_id' => $user->id,
            'data' => json_encode(['type' => 'refund_completed', 'title' => 'Remboursé', 'message' => 'Fini', 'order_id' => 9, 'action_url' => '/orders']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('notifications:normalize --dry-run')->assertExitCode(0);
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('audience'));

        $this->artisan('notifications:normalize')->assertExitCode(0);
        $row  = DB::table('notifications')->where('id', $id)->first();
        $data = json_decode($row->data, true);
        $this->assertSame(['buyer', 'payments'], [$row->audience, $row->category]);
        $this->assertSame('Fini', $data['body']);
        $this->assertSame('/orders', $data['link']);
        $this->assertSame(['order_id' => 9], $data['data']);
        $this->assertTrue(Payload::isNormalized($data));
    }

    // ── E-mails render ────────────────────────────────────────────────────────

    public function test_every_buyer_email_renders_in_french(): void
    {
        $seller = $this->makeSeller('Atelier Test');
        $order  = $this->checkout([$this->makeProduct($seller)]);
        $so     = $this->subOrder($order, $seller);
        $user   = $order->user;

        $notifications = [
            new OrderPlacedNotification($order),
            new OrderStatusNotification($order, 'shipped', [$so->id], null, 'Karim'),
            new OrderStatusNotification($order, 'cancelled', [$so->id], BuyerOrderNotifier::REASON_ADMIN),
            new PaymentNotification($order, 'failed'),
            new AccountSecurityNotification('password_changed'),
        ];

        foreach ($notifications as $n) {
            $mail = $n->toMail($user);
            $html = (string) $mail->render();
            $this->assertNotEmpty($mail->subject);
            $this->assertStringContainsString('Bonjour', $html);
            $this->assertStringNotContainsString('buyer_notifications.', $html, get_class($n) . ' has a missing translation');
            $this->assertStringNotContainsString('buyer_notifications.', json_encode($n->toDatabase($user)));
        }
    }
}
