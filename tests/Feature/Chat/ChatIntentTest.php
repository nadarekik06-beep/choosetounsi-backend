<?php

namespace Tests\Feature\Chat;

use App\Services\Chat\GroqClient;
use App\Services\Chat\IntentExtractor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Rule-based intent + language detection (Groq mocked to fail, so these
 * pass only if the free fast path handles them on its own).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Chat
 */
class ChatIntentTest extends TestCase
{
    use DatabaseTransactions;

    private IntentExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('chatJson')->andReturnNull();
            $mock->shouldReceive('chat')->andReturnNull();
        });

        $this->extractor = app(IntentExtractor::class);
    }

    private function extract(string $message): array
    {
        return $this->extractor->extract($message, ['turns' => [], 'filters' => null, 'shown' => [], 'language' => null, 'user_id' => null]);
    }

    /**
     * @dataProvider platformMessages
     */
    public function test_platform_intents_are_detected_without_llm(string $message, string $intent, ?string $section, string $language): void
    {
        $r = $this->extract($message);

        $this->assertSame($intent, $r['intent'], "intent for: {$message}");
        $this->assertSame($section, $r['section'], "section for: {$message}");
        $this->assertSame($language, $r['language'], "language for: {$message}");
        $this->assertSame('rules', $r['source'], "should not need Groq: {$message}");
    }

    public function platformMessages(): array
    {
        return [
            // become_vendor
            'en vendor'              => ['how do I become a seller?', 'become_vendor', 'apply', 'en'],
            'en plans'               => ['what are the seller plans?', 'become_vendor', 'plans', 'en'],
            'en commission'          => ['how much is the commission?', 'become_vendor', 'commission', 'en'],
            'en add products'        => ['how do I add products?', 'become_vendor', 'add_products', 'en'],
            'fr vendor'              => ['comment devenir vendeur ?', 'become_vendor', 'apply', 'fr'],
            'fr plans'               => ["combien coûte l'abonnement red pepper ?", 'become_vendor', 'plans', 'fr'],
            'arabizi open store'     => ['nheb n7el boutique', 'become_vendor', 'apply', 'ar'],
            'arabizi sell clothes'   => ['nbi3 7wayej 3al site', 'become_vendor', 'apply', 'ar'],
            'darija ar open store'   => ['كيفاش نحل بوتيك؟', 'become_vendor', 'apply', 'ar'],
            'darija ar commission'   => ['قداش العمولة؟', 'become_vendor', 'commission', 'ar'],
            'mixed darija/fr sell'   => ['je veux nbi3 mes produits', 'become_vendor', 'apply', 'ar'],

            // place_order
            'en how to order'        => ['how do I place an order?', 'place_order', 'overview', 'en'],
            'en pay'                 => ['how do I pay?', 'place_order', 'payment', 'en'],
            'en d17'                 => ['can I pay with D17?', 'place_order', 'payment', 'en'],
            'fr coupon'              => ['comment utiliser un coupon ?', 'place_order', 'coupon', 'fr'],
            'en seller coupon'       => ['can I use a seller coupon on a pack?', 'place_order', 'coupon', 'en'],
            'arabizi pay'            => ['kifech nkhalles?', 'place_order', 'payment', 'ar'],
            'darija ar order'        => ['كيفاش نكوموندي؟', 'place_order', 'overview', 'ar'],
            'darija ar pay card'     => ['كيفاش نخلص بالكارت؟', 'place_order', 'payment', 'ar'],
            'mixed darija/fr pay'    => ['kifech nkhalles par carte bancaire', 'place_order', 'payment', 'ar'],

            // track_order
            'en where order'         => ['where is my order?', 'track_order', 'mine', 'en'],
            'en track'               => ['track my order', 'track_order', 'mine', 'en'],
            'fr where order'         => ['où est ma commande ?', 'track_order', 'mine', 'fr'],
            'arabizi where order'    => ['win el commande mte3i?', 'track_order', 'mine', 'ar'],
            'arabizi not arrived'    => ['el colis ma wseltch', 'track_order', 'mine', 'ar'],
            'darija ar where order'  => ['وين الكوموند متاعي؟', 'track_order', 'mine', 'ar'],
            'en status meaning'      => ['what does pending mean for an order?', 'track_order', 'statuses', 'en'],
            'darija ar meaning'      => ['شنوة معنى حالات الطلبية؟', 'track_order', 'statuses', 'ar'],

            // returns_complaints
            'en return'              => ['I want to return a product', 'returns_complaints', 'file', 'en'],
            'fr refund'              => ['comment me faire rembourser ?', 'returns_complaints', 'file', 'fr'],
            'arabizi broken'         => ['el sabbat jani mkasser, nheb nraja3ou', 'returns_complaints', 'file', 'ar'],
            'darija ar return'       => ['نحب نرجع طلبية', 'returns_complaints', 'file', 'ar'],

            // account_help
            'en password'            => ['I forgot my password', 'account_help', 'password', 'en'],
            'en google'              => ['can I login with google?', 'account_help', 'signup', 'en'],
            'fr wallet'              => ['solde de mon portefeuille', 'account_help', 'wallet', 'fr'],
            'mixed darija/fr pass'   => ['nsit el mot de passe', 'account_help', 'password', 'ar'],
            'darija ar signup'       => ['كيفاش نعمل كونت؟', 'account_help', 'signup', 'ar'],

            // categories
            'en categories'          => ['what categories do you have?', 'browse_categories', null, 'en'],
            'arabizi categories'     => ['chnowa 3andkom?', 'browse_categories', null, 'ar'],
        ];
    }

    /**
     * @dataProvider greetings
     */
    public function test_greetings(string $message, string $language): void
    {
        $r = $this->extract($message);
        $this->assertSame('greeting', $r['intent']);
        $this->assertSame($language, $r['language']);
    }

    public function greetings(): array
    {
        return [
            ['hello', 'en'], ['bonjour', 'fr'], ['aslema', 'ar'], ['السلام عليكم', 'ar'], ['عسلامة', 'ar'],
        ];
    }

    public function test_arabizi_search_expands_darija_product_words_and_parses_price(): void
    {
        $r = $this->extract('n7eb sabbat a9al men 80');

        $this->assertSame('search', $r['intent']);
        $this->assertSame('ar', $r['language']);
        $this->assertEqualsWithDelta(80.0, $r['max_price'], 0.001);
        $this->assertContains('chaussure', $r['keywords']);
        $this->assertContains('حذاء', $r['keywords']);
    }

    public function test_arabic_script_darija_search_expands_synonyms(): void
    {
        $r = $this->extract('نحب صباط بين 50 و 120 دينار');

        $this->assertSame('search', $r['intent']);
        $this->assertSame('ar', $r['language']);
        $this->assertEqualsWithDelta(50.0, $r['min_price'], 0.001);
        $this->assertEqualsWithDelta(120.0, $r['max_price'], 0.001);
        $this->assertContains('chaussure', $r['keywords']);
    }

    public function test_mixed_darija_french_search_replies_in_arabic(): void
    {
        $r = $this->extract('nheb une ta9chira moins de 40 dt');

        $this->assertSame('search', $r['intent']);
        $this->assertSame('ar', $r['language']);
        $this->assertEqualsWithDelta(40.0, $r['max_price'], 0.001);
        $this->assertContains('t-shirt', $r['keywords']);
    }

    /**
     * @dataProvider prices
     */
    public function test_price_regex(string $message, ?float $min, ?float $max): void
    {
        $p = $this->extractor->parsePrice($this->extractor->normalize($message));

        $this->assertNotNull($p, $message);
        $this->assertSame($min, $p['min'], "min for: {$message}");
        $this->assertSame($max, $p['max'], "max for: {$message}");
    }

    public function prices(): array
    {
        return [
            ['between 20 and 50 dt', 20.0, 50.0],
            ['entre 20 et 50 dinars', 20.0, 50.0],
            ['من 20 إلى 50 دينار', 20.0, 50.0],
            ['bin 40 w 90', 40.0, 90.0],
            ['under 30dt', null, 30.0],
            ['moins de 30 DT', null, 30.0],
            ['أقل من 30', null, 30.0],
            ['a9al men 80', null, 80.0],
            ['max 100', null, 100.0],
            ['à partir de 60 dt', 60.0, null],
            ['عسل ٢٠-٥٠ دينار', 20.0, 50.0],
        ];
    }

    public function test_order_numbers_are_not_mistaken_for_arabizi(): void
    {
        foreach (['ORD-X3YZ7K9A', 'ORD-A7B9C3D2', 'ORD-9ABC3DEF'] as $number) {
            $this->assertSame('en', $this->extractor->detectLanguage("where is my order {$number}?"), $number);
        }
        // Real arabizi, even capitalised, still counts.
        $this->assertSame('ar', $this->extractor->detectLanguage('N7eb sabbat'));
    }

    public function test_product_search_words_do_not_trigger_how_to_intents(): void
    {
        foreach (['robe rouge', 'premium headphones', 'iphone 13', 'بروتين'] as $message) {
            $this->assertNotContains($this->extract($message)['intent'], IntentExtractor::PLATFORM_INTENTS, $message);
        }
    }
}
