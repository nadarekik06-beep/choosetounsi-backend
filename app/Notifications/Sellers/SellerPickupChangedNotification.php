<?php

namespace App\Notifications\Sellers;

use App\Models\SellerAddressChange;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Admin bell + e-mail: a seller changed their pickup address. Intigo still has
 * the old one, and new parcels for this seller are held until the admin
 * verifies the address and creates / updates it in Intigo.
 */
class SellerPickupChangedNotification extends Notification
{
    use Queueable;

    private const LABELS = [
        'adresse_complete' => 'Adresse complète',
        'intigo_zone_id'   => 'Zone',
        'latitude'         => 'Position (carte)',
        'longitude'        => 'Position (carte)',
        'contact_name'     => 'Contact',
        'phone'            => 'Téléphone',
        'code_postal'      => 'Code postal',
        'indications'      => 'Indications',
    ];

    public function __construct(private User $seller, private SellerAddressChange $change) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    private function shop(): string
    {
        return $this->seller->sellerApplication?->business_name ?: $this->seller->name;
    }

    private function link(): string
    {
        return '/sellers/' . $this->seller->id;
    }

    private function body(): string
    {
        $body = "{$this->shop()} a modifié son adresse de pickup (" . implode(', ', $this->fields()) . '). '
            . 'Vérifiez-la puis mettez-la à jour dans Intigo : les nouveaux colis de ce vendeur sont bloqués en attendant.';
        if ($this->change->pending_pickups > 0) {
            $body .= " ⚠ {$this->change->pending_pickups} commande(s) pas encore enlevée(s).";
        }
        return $body;
    }

    /** @return string[] */
    private function fields(): array
    {
        return array_values(array_unique(array_map(fn ($f) => self::LABELS[$f] ?? $f, $this->change->changed_fields ?? [])));
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'     => 'seller_pickup_changed',
            'category' => 'sellers',
            'audience' => 'admin',
            'title'    => 'Adresse de pickup modifiée : mettre à jour dans Intigo',
            'body'     => $this->body(),
            'link'     => $this->link(),
            'icon'     => 'map-pin',
            'action'   => $this->change->pending_pickups > 0 ? 'urgent' : 'pending',
            'data'     => [
                'seller_id'       => $this->seller->id,
                'change_id'       => $this->change->id,
                'changed_fields'  => $this->change->changed_fields,
                'pending_pickups' => $this->change->pending_pickups,
            ],
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $new = $this->change->new_values ?? [];
        $old = $this->change->old_values ?? [];
        $url = admin_url() . $this->link();

        $mail = (new MailMessage)
            ->subject("Adresse de pickup modifiée : mettre à jour dans Intigo ({$this->shop()})")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("**{$this->shop()}** a modifié son adresse de pickup. Intigo a encore l'ancienne adresse.");

        if ($this->change->pending_pickups > 0) {
            $mail->line("⚠ **{$this->change->pending_pickups} commande(s) pas encore enlevée(s)** chez ce vendeur : le livreur risque d'aller à l'ancienne adresse.");
        }

        $rows = [
            'Adresse complète' => ['adresse_complete', 'adresse_complete'],
            'Zone'             => ['zone_label', 'intigo_zone_id'],
            'Contact'          => ['contact_name', 'contact_name'],
            'Téléphone'        => ['phone', 'phone'],
            'Code postal'      => ['code_postal', 'code_postal'],
            'Indications'      => ['indications', 'indications'],
        ];
        foreach ($rows as $label => [$key, $field]) {
            if (in_array($field, $this->change->changed_fields ?? [], true)) {
                $mail->line("**{$label}** : " . ($old[$key] ?? '—') . ' → ' . ($new[$key] ?? '—'));
            }
        }
        if (array_intersect(['latitude', 'longitude'], $this->change->changed_fields ?? [])) {
            $mail->line('**Position** : ' . ($old['latitude'] ?? '—') . ', ' . ($old['longitude'] ?? '—') . ' → ' . ($new['latitude'] ?? '—') . ', ' . ($new['longitude'] ?? '—'));
        }

        return $mail
            ->line('Les nouveaux colis de ce vendeur ne partiront pas chez Intigo tant que l\'adresse n\'est pas vérifiée et créée dans Intigo.')
            ->action('Ouvrir la fiche vendeur', $url);
    }
}
