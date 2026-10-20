<?php

namespace Tests\Feature;

use App\Mail\ContactMessageMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * POST /api/contact: validated, queued to the team inbox (mail.contact_to) on
 * "emails" with Reply-To = the visitor; honeypot / too-fast = silent drop;
 * 5 per 10 minutes per IP.
 */
class ContactFormTest extends TestCase
{
    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'Sara Ben Ali', 'email' => 'sara@example.com', 'phone' => '+216 22 123 456',
            'topic' => 'order', 'message' => 'Bonjour, où en est ma commande ?', 'website' => '', 'elapsed_ms' => 8000,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.contact_to' => 'team@example.com']);
        Mail::fake();
        foreach (['contact10:127.0.0.1', 'contactday:127.0.0.1'] as $key) {
            RateLimiter::clear(md5('contact' . $key));
        }
    }

    public function test_a_message_is_queued_to_the_team_inbox(): void
    {
        $this->postJson('/api/contact', $this->payload())->assertOk()->assertJsonPath('success', true);

        Mail::assertQueued(ContactMessageMail::class, fn (ContactMessageMail $m) =>
            $m->hasTo('team@example.com') && $m->queue === 'emails'
            && $m->data['email'] === 'sara@example.com' && $m->data['topic'] === 'order');
    }

    public function test_the_email_replies_to_the_visitor_and_escapes_input(): void
    {
        $mail = new ContactMessageMail(['name' => 'Sara', 'email' => 'sara@example.com', 'phone' => null, 'topic' => 'return',
            'message' => '<script>alert(1)</script>', 'locale' => 'ar', 'user_id' => null, 'ip' => '1.2.3.4']);
        config(['mail.reply_to.address' => 'global@example.com']);
        $mailer = (new \Illuminate\Mail\MailManager(app()))->mailer('array');   // real one: setUp fakes Mail
        app()->instance('mailer', $mailer);
        $html = $mail->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Retour / remboursement', $html);

        $mail->to('team@example.com')->send($mailer);
        $sent = $mailer->getSwiftMailer()->getTransport()->messages()->last();
        $this->assertSame(['sara@example.com' => 'Sara'], $sent->getReplyTo(), 'only the visitor, not the global Reply-To');
    }

    public function test_bots_get_a_200_but_nothing_is_sent(): void
    {
        $this->postJson('/api/contact', $this->payload(['website' => 'http://spam.example']))->assertOk();
        $this->postJson('/api/contact', $this->payload(['elapsed_ms' => 800]))->assertOk();
        Mail::assertNothingQueued();
    }

    public function test_validation(): void
    {
        $this->postJson('/api/contact', $this->payload(['email' => 'nope', 'message' => 'short', 'topic' => 'hack']))
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'message', 'topic']);
        Mail::assertNothingQueued();
    }

    public function test_rate_limited_to_five_per_ten_minutes(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/api/contact', $this->payload())->assertOk();
        }
        $this->postJson('/api/contact', $this->payload())->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        Mail::assertQueued(ContactMessageMail::class, 5);
    }
}
