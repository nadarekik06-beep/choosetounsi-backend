<?php

namespace Tests\Feature\Ads;

use App\Jobs\GenerateAdCopy;
use App\Mail\Marketing\PickedForYouMail;
use App\Mail\Marketing\StillLookingMail;
use App\Models\Sponsorship;
use App\Models\SponsorshipEvent;
use App\Models\User;
use App\Models\UserInteraction;
use App\Services\Ads\AdEmailService;
use App\Services\Ads\AdServer;
use App\Services\Ads\MarketingConsent;
use App\Services\Ads\SponsorshipService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Marketing consent, one-click unsubscribe, weekly digest and triggered deal
 * e-mails (with billed-on-click ad links).
 *
 * Run only this file:  php vendor/bin/phpunit tests/Feature/Ads/MarketingEmailTest.php
 */
class MarketingEmailTest extends TestCase
{
    use DatabaseTransactions, MakesAds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAds();
        Notification::fake();
        Bus::fake([GenerateAdCopy::class]);
        Mail::fake();
    }

    private function optedIn(array $attrs = []): User
    {
        $u = $this->makeUser('client', $attrs + ['email_verified_at' => now(), 'locale' => 'fr']);
        return app(MarketingConsent::class)->set($u, true);
    }

    private function subcategory($category): int
    {
        $s = Str::random(6);
        return DB::table('subcategories')->insertGetId([
            'category_id' => $category->id, 'name' => "Sub $s", 'name_fr' => "Sous $s", 'name_ar' => "Sub $s",
            'slug' => "sub-$s", 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function campaignFor($category, array $productAttrs = []): Sponsorship
    {
        $seller = $this->seller();
        $this->fund($seller, 50);
        $c = app(SponsorshipService::class)->create($seller, $this->campaignData($this->readyProduct($seller, $category, $productAttrs)));
        AdServer::flushEligible();
        return $c;
    }

    private function views(User $u, $product, int $n): void
    {
        foreach (range(1, $n) as $i) {
            UserInteraction::create(['user_id' => $u->id, 'product_id' => $product->id, 'seller_id' => $product->seller_id,
                'category_id' => $product->category_id, 'event_type' => 'view', 'created_at' => now()->subMinutes($i)]);
        }
    }

    public function test_consent_endpoints_and_one_click_unsubscribe(): void
    {
        $user = $this->makeUser('client', ['locale' => 'fr']);
        $this->assertFalse((bool) $user->fresh()->marketing_emails_opt_in, 'off by default');
        $this->assertSame(40, strlen($user->fresh()->unsubscribe_token));

        Sanctum::actingAs($user);
        $this->getJson('/api/account/marketing-consent')->assertOk()->assertJsonPath('data.opt_in', false);
        $this->postJson('/api/account/marketing-consent', ['opt_in' => true])->assertOk()->assertJsonPath('data.opt_in', true);
        $this->assertNotNull($user->fresh()->marketing_opt_in_at);

        $token = $user->fresh()->unsubscribe_token;
        // The page speaks the user's language (SetLocale keeps users.locale in sync with API calls).
        $locale = $user->fresh()->locale ?: config('app.locale');
        $this->get("/unsubscribe/{$token}")->assertOk()->assertSee(__('emails.marketing.unsubscribed.title', [], $locale), false);
        $this->assertFalse((bool) $user->fresh()->marketing_emails_opt_in);

        app(MarketingConsent::class)->set($user->fresh(), true);
        $this->post("/unsubscribe/{$token}")->assertNoContent();
        $this->assertFalse((bool) $user->fresh()->marketing_emails_opt_in);
        $this->get('/unsubscribe/' . str_repeat('x', 40))->assertNotFound();
    }

    public function test_weekly_digest_mixes_organic_and_labelled_ads_and_respects_the_gap(): void
    {
        $cat = $this->makeCategory();
        foreach (range(1, 8) as $_) {
            $this->readyProduct($this->makeUser('seller'), $cat);
        }
        $c    = $this->campaignFor($cat);
        $user = $this->optedIn();
        $this->views($user, $c->product, 3);

        $this->assertTrue(app(AdEmailService::class)->sendDigest($user));

        Mail::assertQueued(PickedForYouMail::class, function (PickedForYouMail $mail) use ($user, $c) {
            $ads = array_values(array_filter($mail->items, fn ($i) => $i['sponsored']));
            return $mail->hasTo($user->email)
                && count($mail->items) >= 3
                && $ads && $ads[0]['id'] === $c->product_id
                && str_contains($ads[0]['url'], '/api/ads/r/')
                && str_contains($mail->unsubscribeUrl, $user->unsubscribe_token);
        });
        $this->assertSame(1, SponsorshipEvent::where('sponsorship_id', $c->id)->where('event', 'impression')->count(), 'impression at send');
        $this->assertNotNull($user->fresh()->last_marketing_email_at);

        $this->assertFalse(app(AdEmailService::class)->sendDigest($user->fresh()), 'one marketing e-mail per 7 days');
        $this->assertFalse(app(AdEmailService::class)->sendDigest($this->makeUser()), 'no consent, no e-mail');
    }

    public function test_interest_email_for_a_deal_in_a_browsed_subcategory(): void
    {
        $cat = $this->makeCategory();
        $sub = $this->subcategory($cat);
        $c   = $this->campaignFor($cat, ['subcategory_id' => $sub, 'delivery_fee' => 0]);
        $viewed = $this->readyProduct($this->makeUser('seller'), $cat, ['subcategory_id' => $sub]);
        $user = $this->optedIn();
        $this->views($user, $viewed, 3);

        $this->artisan('ads:send-interest-emails', ['--user' => $user->id])->assertExitCode(0);

        Mail::assertQueued(StillLookingMail::class, fn (StillLookingMail $m) => $m->item['id'] === $c->product_id
            && $m->item['free_delivery'] && str_starts_with($m->category, 'Sous'));
    }

    public function test_email_ad_link_bills_the_click_and_redirects(): void
    {
        $cat  = $this->makeCategory();
        $c    = $this->campaignFor($cat);
        $user = $this->optedIn();
        $this->views($user, $c->product, 3);

        $ad = app(AdServer::class)->serve(new \App\Services\Ads\AdRequest('email_digest', $user->id, null, 1, forEmail: true))['ads'][0];

        $this->get('/api/ads/r/' . $ad['ad_token'])
            ->assertRedirect(rtrim(config('app.frontend_url'), '/') . "/products/{$c->product->slug}?utm_source=email&utm_medium=ad");

        $this->assertSame(1, SponsorshipEvent::where('sponsorship_id', $c->id)->where('event', 'click')->where('billable', true)->count());
        $this->get('/api/ads/r/not.valid')->assertRedirect(rtrim(config('app.frontend_url'), '/'));
    }

    public function test_templates_render_in_every_locale_with_unsubscribe_link(): void
    {
        $user = $this->optedIn();
        $item = ['id' => 1, 'name' => 'Tapis berbère', 'image' => null, 'price' => 99, 'original' => 120, 'free_delivery' => true,
                 'copy' => 'Fait main', 'sponsored' => true, 'url' => 'https://example.test/p'];

        foreach (['en', 'fr', 'ar'] as $locale) {
            app()->setLocale($locale);
            // (Mail is faked in this class, so render the views the mailables use.)
            $html = view('emails.marketing.digest', ['user' => $user, 'items' => [$item, ['sponsored' => false] + $item, $item], 'unsubscribeUrl' => 'https://example.test/unsub'])->render();
            $this->assertStringContainsString(__('emails.marketing.sponsored'), $html);
            $this->assertStringContainsString('https://example.test/unsub', $html);
            $this->assertStringContainsString($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', $html);

            $html = view('emails.marketing.interest', ['user' => $user, 'category' => 'Tapis', 'item' => $item, 'unsubscribeUrl' => 'https://example.test/unsub'])->render();
            $this->assertStringContainsString(__('emails.marketing.interest.title', ['category' => 'Tapis']), $html);
        }
    }
}
