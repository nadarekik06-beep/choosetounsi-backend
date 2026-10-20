<?php

namespace Tests\Feature;

use App\Mail\MailTestMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Global From / Reply-To (config/mail.php) on every e-mail sent, and the
 * mail:test command queuing on the "emails" queue.
 */
class MailReplyToTest extends TestCase
{
    private function sendWith(?string $replyTo): \Swift_Mime_SimpleMessage
    {
        config([
            'mail.default'          => 'array',
            'mail.from.address'     => 'contact@choosetounsi.tn',
            'mail.from.name'        => "Choose'Tounsi",
            'mail.reply_to.address' => $replyTo,
            'mail.reply_to.name'    => "Choose'Tounsi",
        ]);
        app('mail.manager')->forgetMailers();

        Mail::to('someone@example.com')->send(new MailTestMail('2026-10-20T18:00:00+01:00'));

        $messages = app('mail.manager')->mailer('array')->getSwiftMailer()->getTransport()->messages();
        $this->assertCount(1, $messages);
        return $messages->first();
    }

    public function test_every_mail_gets_the_global_from_and_reply_to(): void
    {
        $message = $this->sendWith('replies@example.com');

        $this->assertSame(['contact@choosetounsi.tn' => "Choose'Tounsi"], $message->getFrom());
        $this->assertSame(['replies@example.com' => "Choose'Tounsi"], $message->getReplyTo());
    }

    public function test_no_reply_to_header_when_not_configured(): void
    {
        $this->assertEmpty($this->sendWith(null)->getReplyTo());
    }

    public function test_mail_test_command_queues_on_the_emails_queue(): void
    {
        Mail::fake();
        $this->artisan('mail:test', ['to' => 'someone@example.com'])->assertExitCode(0);
        Mail::assertQueued(MailTestMail::class, fn ($m) => $m->hasTo('someone@example.com') && $m->queue === 'emails');

        $this->artisan('mail:test', ['to' => 'not-an-address'])->assertExitCode(1);
    }

    public function test_mail_test_can_send_the_seller_approved_email_to_another_address(): void
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $user = \App\Models\User::create(['name' => 'Approved Seller', 'email' => 'approved_' . uniqid() . '@test.local', 'password' => bcrypt('x'), 'role' => 'seller', 'is_active' => true]);
            \App\Models\SellerApplication::create([
                'user_id' => $user->id, 'full_name' => 'Approved Seller', 'phone_number' => '55111222', 'business_name' => 'Shop A',
                'business_category' => 'crafts', 'business_description' => 'x', 'wilaya' => 'Sfax', 'city' => 'Sfax',
                'pickup_address' => 'Route', 'pickup_postal_code' => '3021', 'status' => 'approved',
            ]);
            Mail::fake();
            $this->artisan('mail:test', ['to' => 'someone@example.com', '--seller-approved' => true])->assertExitCode(0);
            Mail::assertQueued(\App\Mail\SellerApplicationApprovedMail::class,
                fn ($m) => $m->hasTo('someone@example.com') && !$m->hasTo($user->email) && $m->queue === 'emails');
        } finally {
            \Illuminate\Support\Facades\DB::rollBack();
        }
    }
}
