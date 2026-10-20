<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * A message from the storefront contact form, to the team inbox
 * (config mail.contact_to), in French. Replying answers the visitor directly.
 */
class ContactMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Topics of the form (storefront contact page) → label for the team. */
    public const TOPICS = [
        'order'   => 'Commande / livraison',
        'return'  => 'Retour / remboursement',
        'seller'  => 'Vendre sur ChooseTounsi',
        'account' => 'Compte',
        'other'   => 'Autre',
    ];

    /** @param array{name: string, email: string, phone: ?string, topic: string, message: string, locale: string, user_id: ?int, ip: ?string} $data */
    public function __construct(public array $data)
    {
        $this->queue  = 'emails';
        $this->locale = 'fr';
    }

    public function build(): self
    {
        $d = $this->data;

        return $this
            ->subject('[Contact] ' . (self::TOPICS[$d['topic']] ?? 'Autre') . ' — ' . $d['name'])
            ->view('emails.contact.message', ['d' => $d, 'topic' => self::TOPICS[$d['topic']] ?? 'Autre'])
            // Replies go to the visitor only (replaces the global Reply-To)
            ->withSwiftMessage(fn ($message) => $message->setReplyTo([$d['email'] => $d['name']]));
    }
}
