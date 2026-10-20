<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Support\AppLinks;
use App\Support\EmailLinkAudit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Delivery\DeliveryTestHelpers;
use Tests\TestCase;

/**
 * Renders every e-mail the backend sends (App\Support\EmailLinkAudit) and
 * fails if a link points at the API domain or localhost, is relative or "#".
 * Page links belong on the storefront (frontend_url) or the admin panel
 * (admin_url); the API domain only for its endpoints (unsubscribe, ad click
 * redirect, /storage images).
 */
class EmailLinksTest extends TestCase
{
    use DatabaseTransactions, DeliveryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url'          => 'https://api.choosetounsi.tn',
            'app.frontend_url' => 'https://choosetounsi.tn',
            'app.admin_url'    => 'https://admin.choosetounsi.tn',
            'mail.default'     => 'array',
        ]);
        \Illuminate\Support\Facades\URL::forceRootUrl('https://api.choosetounsi.tn');   // url() as in production
        $this->setUpDelivery();
    }

    public function test_every_email_links_to_the_storefront_or_admin_panel_never_the_api(): void
    {
        // Sample data: newest rows, so EmailLinkAudit picks these
        $seller  = $this->seller();
        $product = $this->product($seller, 40);
        $this->addToCart($product);
        $order = $this->checkout();
        Complaint::create([
            'user_id' => $this->customer->id, 'order_id' => $order['order_id'], 'seller_id' => $seller->id,
            'complaint_type' => 'damaged', 'resolution_type' => 'return_refund',
            'description' => 'Arrived broken.', 'status' => Complaint::STATUS_REQUESTED, 'reference' => 'RET-L' . $order['order_id'],
        ]);

        $rendered = (new EmailLinkAudit())->renderAll();

        // These have sample data in every database: they must render
        foreach ([
            'SellerApplicationApprovedMail', 'SellerApplicationSubmittedMail', 'WelcomeUserMail', 'PasswordResetMail',
            'VerificationCodeMail', 'PickedForYouMail', 'StillLookingMail', 'TargetedCouponMail',
            'OrderPlacedNotification', 'OrderStatusNotification', 'NewSellerOrderNotification',
            'SellerOrderCancelledNotification', 'SellerPickupReminderNotification', 'ComplaintNotification',
            'ComplaintCreatedNotification (seller)', 'ComplaintCreatedNotification (admin)',
            'SellerRejectedComplaintNotification', 'ReturnAdminNotification', 'LowStockNotification',
            'ForecastAlertNotification', 'ForecastDigestNotification', 'GrowthRadarNotification', 'ProfitGoalNotification',
        ] as $required) {
            $this->assertArrayHasKey($required, $rendered);
            $this->assertNotNull($rendered[$required]['html'], "{$required} did not render: " . $rendered[$required]['error']);
        }

        $problems = [];
        foreach ($rendered as $name => $r) {
            if ($r['html'] === null) {
                if ($r['error'] !== 'no sample data') $problems[] = "{$name}: render error {$r['error']}";
                continue;
            }
            foreach (EmailLinkAudit::links($r['html'], true) as $link) {
                if ($p = EmailLinkAudit::problem($link)) $problems[] = "{$name}: {$link} ({$p})";
                $this->assertStringNotContainsString('localhost', $link, $name);
            }
        }
        $this->assertSame([], $problems, "Bad e-mail links:\n" . implode("\n", $problems));

        // The reported bug: the approved e-mail's dashboard button opens the seller space on the storefront
        $approved = EmailLinkAudit::links($rendered['SellerApplicationApprovedMail']['html']);
        $this->assertContains('https://choosetounsi.tn/seller', $approved);
        $this->assertContains('https://choosetounsi.tn/seller/products?create=1', $approved);
        $this->assertSame([], array_values(array_filter($approved, fn ($l) => str_contains($l, 'api.choosetounsi.tn'))));

        // Footer: privacy, terms and contact pages on every e-mail layout (custom, transactional, Laravel's)
        $footer = ['https://choosetounsi.tn/privacy', 'https://choosetounsi.tn/terms', 'https://choosetounsi.tn/contact'];
        foreach (['SellerApplicationApprovedMail', 'WelcomeUserMail', 'NewSellerOrderNotification', 'LowStockNotification',
                  'OrderPlacedNotification', 'SellerRejectedComplaintNotification', 'PickedForYouMail'] as $name) {
            $links = EmailLinkAudit::links($rendered[$name]['html']);
            foreach ($footer as $page) {
                $this->assertContains($page, $links, "{$name}: footer link {$page}");
            }
        }

        // Unsubscribe: storefront confirmation page on marketing mail only, never on transactional mail
        $this->assertNotEmpty(array_filter(EmailLinkAudit::links($rendered['PickedForYouMail']['html']),
            fn ($l) => str_starts_with($l, 'https://choosetounsi.tn/unsubscribe/')));
        $this->assertEmpty(array_filter(EmailLinkAudit::links($rendered['SellerApplicationApprovedMail']['html']),
            fn ($l) => str_contains($l, '/unsubscribe/')));

        // Admin e-mails open the admin panel
        $this->assertContains('https://admin.choosetounsi.tn/complaints?id=' . Complaint::latest('id')->value('id'),
            EmailLinkAudit::links($rendered['ComplaintCreatedNotification (admin)']['html']));
    }

    public function test_the_rule_catches_api_and_local_links(): void
    {
        $this->assertNotNull(EmailLinkAudit::problem('https://api.choosetounsi.tn/seller/dashboard'));
        $this->assertNotNull(EmailLinkAudit::problem('http://localhost:3000/seller'));
        $this->assertNotNull(EmailLinkAudit::problem('/seller'));
        $this->assertNotNull(EmailLinkAudit::problem('#'));
        $this->assertNull(EmailLinkAudit::problem('https://choosetounsi.tn/seller'));
        $this->assertNull(EmailLinkAudit::problem('https://admin.choosetounsi.tn/complaints?id=1'));
        // Unsubscribe body links go to the storefront page now: the backend URL is only the List-Unsubscribe header
        $this->assertNotNull(EmailLinkAudit::problem('https://api.choosetounsi.tn/unsubscribe/' . str_repeat('a', 40)));
        $this->assertNull(EmailLinkAudit::problem('https://choosetounsi.tn/unsubscribe/' . str_repeat('a', 40)));
        $this->assertNull(EmailLinkAudit::problem('https://api.choosetounsi.tn/api/ads/r/abc.def'));
        $this->assertNull(EmailLinkAudit::problem('https://api.choosetounsi.tn/storage/products/a.jpg'));
        $this->assertNull(EmailLinkAudit::problem('mailto:contact@choosetounsi.tn'));
    }

    public function test_helpers(): void
    {
        $this->assertSame('https://choosetounsi.tn/seller', frontend_url('/seller'));
        $this->assertSame('https://choosetounsi.tn/seller', frontend_url('seller'));
        $this->assertSame('https://choosetounsi.tn', frontend_url());
        $this->assertSame('https://choosetounsi.tn/', frontend_url('/'));
        $this->assertSame('https://choosetounsi.tn/?utm_source=email', frontend_url('?utm_source=email'));
        config(['app.frontend_url' => 'https://choosetounsi.tn/']);
        $this->assertSame('https://choosetounsi.tn/orders?order=3', AppLinks::frontend('/orders?order=3'));
        $this->assertSame('https://admin.choosetounsi.tn/complaints?id=3', admin_url('/complaints?id=3'));
    }

    /** Nothing in the e-mail code may build a page link from the API domain again. */
    public function test_email_code_never_uses_api_url_builders(): void
    {
        $files = array_merge(
            glob(base_path('app/Mail/{,*/}*.php'), GLOB_BRACE),
            $this->files(base_path('app/Notifications')),
            $this->files(resource_path('views/emails')),
            $this->files(resource_path('views/vendor/mail')),
        );
        $bad = [];
        foreach ($files as $file) {
            $code = file_get_contents($file);
            if (preg_match_all("/config\\(\\s*['\"]app\\.url['\"]\\s*\\)|env\\(\\s*['\"]APP_URL['\"]|(?<![\\w>:])url\\((?!'https:\\/\\/fonts\\.googleapis)|(?<![\\w>:])route\\(|(?<![\\w>:])asset\\(|api\\.choosetounsi|localhost/", $code, $m)) {
                $bad[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ': ' . implode(', ', array_unique($m[0]));
            }
        }
        $this->assertSame([], $bad, "Use frontend_url() / admin_url() in e-mails:\n" . implode("\n", $bad));
    }

    private function files(string $dir): array
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        return array_values(array_map('strval', array_filter(iterator_to_array($it), fn ($f) => $f->isFile() && str_ends_with($f->getFilename(), '.php'))));
    }
}
