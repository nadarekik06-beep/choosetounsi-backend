<?php

namespace Tests\Feature\Chat;

use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Chat\ActionFactory;
use App\Services\Chat\GroqClient;
use App\Services\ChatMemory;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /api/ai/chat end to end, with Groq mocked as unavailable (so the
 * "always reply" fallbacks are what's tested) and throwaway users/orders
 * rolled back after each test.
 *
 * Run only this folder:  php vendor/bin/phpunit tests/Feature/Chat
 */
class ChatEndpointTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('chatJson')->andReturnNull();
            $mock->shouldReceive('chat')->andReturnNull();
        });
    }

    private function chat(string $message, ?string $token = null, ?string $session = null, ?string $locale = null)
    {
        // Sanctum's guard caches the resolved user, and withHeaders() persists
        // across requests in a test — reset both so a "guest" call is really anonymous.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders($token ? ['Authorization' => "Bearer {$token}"] : [])
            ->postJson('/api/ai/chat', [
                'message'    => $message,
                'session_id' => $session ?? 'test_' . Str::random(12),
            ] + ($locale ? ['locale' => $locale] : []));
    }

    private function makeUser(string $role = 'client'): User
    {
        return User::create([
            'name'      => 'Chat Test ' . Str::random(5),
            'email'     => 'chat_' . Str::random(10) . '@test.local',
            'password'  => bcrypt('secret-password'),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    private function makeOrder(User $user, string $status = 'out_for_delivery'): string
    {
        $number = 'ORD-' . strtoupper(Str::random(8));
        DB::table('orders')->insert([
            'user_id'        => $user->id,
            'order_number'   => $number,
            'total_amount'   => 42.5,
            'status'         => $status,
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        return $number;
    }

    public function test_response_shape_is_always_complete(): void
    {
        $this->chat('hello')
            ->assertOk()
            ->assertJsonStructure(['success', 'reply', 'language', 'intent', 'products', 'steps', 'actions'])
            ->assertJsonPath('intent', 'greeting')
            ->assertJsonCount(4, 'actions');
    }

    public function test_how_to_answer_uses_live_plan_prices_and_commission(): void
    {
        $res  = $this->chat('what are the seller plans?')->assertOk()->assertJsonPath('intent', 'become_vendor');
        $text = collect($res->json('steps'))->map(fn ($s) => $s['title'] . ' ' . $s['description'])->implode("\n");

        $red   = (int) SellerSubscription::PLAN_PRICES['red'];
        $black = (int) SellerSubscription::PLAN_PRICES['black'];
        $range = app(CommissionService::class)->rateRangeForPlan('black');

        $this->assertStringContainsString("{$red} DT", $text);
        $this->assertStringContainsString("{$black} DT", $text);
        $this->assertStringContainsString((int) $range['min'] . '–' . (int) $range['max'] . '%', $text);
    }

    public function test_every_link_action_is_internal(): void
    {
        $messages = ['comment devenir vendeur ?', 'how do I pay?', 'I want to return a product', 'I forgot my password', 'what categories do you have?', 'where is my order?'];

        foreach ($messages as $message) {
            foreach ($this->chat($message)->json('actions') as $action) {
                $this->assertContains($action['type'], ['link', 'quick_reply']);
                if ($action['type'] === 'link') {
                    $this->assertTrue(ActionFactory::isAllowedUrl($action['url']), "{$message} → {$action['url']}");
                } else {
                    $this->assertNotEmpty($action['message']);
                }
            }
        }
    }

    public function test_guest_asking_for_orders_gets_a_login_button(): void
    {
        $res = $this->chat('where is my order?')->assertOk()->assertJsonPath('intent', 'track_order');

        $this->assertSame([], $res->json('steps'));
        $this->assertContains('/auth/login?redirect=/orders', array_column($res->json('actions'), 'url'));
    }

    public function test_orders_are_only_shown_to_their_owner(): void
    {
        $alice = $this->makeUser();
        $bob   = $this->makeUser();
        $aliceOrder = $this->makeOrder($alice);
        $bobOrder   = $this->makeOrder($bob, 'delivered');

        $token = $alice->createToken('chat-test')->plainTextToken;

        // Even naming Bob's order number explicitly must not reveal it.
        $res   = $this->chat("where is my order {$bobOrder}?", $token)->assertOk()->assertJsonPath('intent', 'track_order');
        $steps = json_encode($res->json('steps'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString($aliceOrder, $steps);
        $this->assertStringNotContainsString($bobOrder, $steps);
        $this->assertStringContainsString('Out for Delivery', $steps);
    }

    public function test_order_answers_are_not_cached_across_users(): void
    {
        $alice = $this->makeUser();
        $aliceOrder = $this->makeOrder($alice);

        $this->chat('track my order', $alice->createToken('t')->plainTextToken, 'shared_session_1');

        // Same message, same session id, but a guest now: must not see Alice's order.
        $res = $this->chat('track my order', null, 'shared_session_1');
        $this->assertStringNotContainsString($aliceOrder, $res->getContent());
        $this->assertContains('/auth/login?redirect=/orders', array_column($res->json('actions'), 'url'));
    }

    public function test_memory_is_reset_when_the_user_changes(): void
    {
        $memory = app(ChatMemory::class);
        $memory->remember('mem_session', 7, 'hi', 'hello', 'en', ['keywords' => ['robe']], [['id' => 1, 'price' => 10]]);

        $this->assertNotEmpty($memory->get('mem_session', 7)['turns']);
        $this->assertSame([], $memory->get('mem_session', null)['turns']);
        $this->assertNull($memory->get('mem_session', 8)['filters']);
    }

    public function test_search_still_replies_when_groq_is_down(): void
    {
        $res = $this->chat('robe entre 20 et 50 dt', null, null, 'fr')->assertOk();

        $this->assertNotEmpty($res->json('reply'));
        $this->assertSame('fr', $res->json('language'));
    }

    public function test_reply_uses_the_storefront_language(): void
    {
        // French message, Arabic storefront → the assistant answers in Arabic.
        $this->assertSame('ar', $this->chat('bonjour', null, null, 'ar')->assertOk()->json('language'));
        $this->assertSame('en', $this->chat('bonjour', null, null, 'en')->assertOk()->json('language'));
    }

    /**
     * @dataProvider urls
     */
    public function test_action_url_allowlist(string $url, bool $allowed): void
    {
        $this->assertSame($allowed, ActionFactory::isAllowedUrl($url), $url);
    }

    public function urls(): array
    {
        return [
            ['/become-a-vendor', true],
            ['/orders', true],
            ['/category/fashion-clothing', true],
            ['/products/robe-rouge-ete', true],
            ['/auth/login?redirect=/orders', true],
            ['#cart', true],
            ['https://evil.example/phish', false],
            ['//evil.example', false],
            ['javascript:alert(1)', false],
            ['/auth/login?redirect=https://evil.example', false],
            ['/auth/login?redirect=//evil.example', false],
            ['/admin', false],
            ['/category/../admin', false],
            ['/cart', false],
            ['/orders?x=<script>', false],
        ];
    }

    public function test_public_platform_endpoints_match_the_code(): void
    {
        config(['services.d17.account_number' => '']);
        $this->getJson('/api/checkout/payment-info')->assertOk()->assertJsonPath('data.d17_account_number', null);

        $plans = collect($this->getJson('/api/seller-plans')->assertOk()->json('data'))->keyBy('key');
        foreach (['free', 'red', 'black'] as $plan) {
            $range = app(CommissionService::class)->rateRangeForPlan($plan);
            $this->assertEquals(SellerSubscription::PLAN_PRICES[$plan], $plans[$plan]['price']);
            $this->assertEquals($range['min'], $plans[$plan]['commission_min']);
            $this->assertEquals($range['max'], $plans[$plan]['commission_max']);
        }
    }
}
