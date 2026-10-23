<?php

namespace App\Notifications\Sellers;

use App\Models\SellerApplication;
use App\Models\SellerProfileChange;
use App\Services\Sellers\StoreProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Admin bell + e-mail: a seller changed their logo, description or activity
 * categories from "Paramètres de la boutique". Already live on the storefront;
 * the admin can restore the old logo / description from the seller page.
 */
class SellerStoreProfileChangedNotification extends Notification
{
    use Queueable;

    private string $shop;
    /** @var string[] */
    private array $added;
    /** @var string[] */
    private array $removed;

    public function __construct(private SellerApplication $app, private SellerProfileChange $change, StoreProfileService $profiles)
    {
        $this->shop    = $app->business_name ?: ('Vendeur #' . $app->user_id);
        $this->added   = array_map(fn ($n) => $profiles->categoryLabel($n), $change->new_value['added'] ?? []);
        $this->removed = array_map(fn ($n) => $profiles->categoryLabel($n), $change->new_value['removed'] ?? []);
    }

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    private function link(): string
    {
        return '/sellers/' . $this->app->user_id;
    }

    private function title(): string
    {
        return match ($this->change->field) {
            SellerProfileChange::LOGO        => "Logo modifié : {$this->shop}",
            SellerProfileChange::DESCRIPTION => "Description modifiée : {$this->shop}",
            default                          => "Catégories d'activité modifiées : {$this->shop}",
        };
    }

    private function body(): string
    {
        $shop = $this->shop;
        return match ($this->change->field) {
            SellerProfileChange::LOGO        => "Le vendeur {$shop} a changé le logo de sa boutique. L'ancien logo est conservé : vous pouvez le restaurer depuis la fiche vendeur.",
            SellerProfileChange::DESCRIPTION => "Le vendeur {$shop} a modifié la description de sa boutique : « "
                . Str::limit((string) ($this->change->new_value['text'] ?? ''), 140) . ' »',
            default => $this->categoriesSentence(),
        };
    }

    private function categoriesSentence(): string
    {
        $parts = [];
        if ($this->added) {
            $parts[] = count($this->added) === 1
                ? "a ajouté la catégorie {$this->added[0]}"
                : 'a ajouté les catégories ' . implode(', ', $this->added);
        }
        if ($this->removed) {
            $parts[] = count($this->removed) === 1
                ? "a retiré la catégorie {$this->removed[0]}"
                : 'a retiré les catégories ' . implode(', ', $this->removed);
        }
        return "Le vendeur {$this->shop} " . ($parts ? implode(' et ', $parts) : "a réordonné ses catégories d'activité") . '.';
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type'     => 'seller_store_profile_changed',
            'category' => 'sellers',
            'audience' => 'admin',
            'title'    => $this->title(),
            'body'     => $this->body(),
            'link'     => $this->link(),
            'icon'     => 'store',
            'action'   => 'info',
            'data'     => [
                'seller_id' => $this->app->user_id,
                'change_id' => $this->change->id,
                'field'     => $this->change->field,
                'added'     => $this->change->new_value['added'] ?? [],
                'removed'   => $this->change->new_value['removed'] ?? [],
            ],
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting("Bonjour {$notifiable->name},")
            ->line($this->body());

        $old = $this->change->old_value ?? [];
        $new = $this->change->new_value ?? [];

        if ($this->change->field === SellerProfileChange::LOGO) {
            $oldUrl = StoreProfileService::logoUrl($old['path'] ?? null);
            $newUrl = StoreProfileService::logoUrl($new['path'] ?? null);
            $mail->line('**Ancien logo :** ' . ($oldUrl ? "![Ancien logo]({$oldUrl})" : '— (aucun)'))
                 ->line("**Nouveau logo :** ![Nouveau logo]({$newUrl})");
        } elseif ($this->change->field === SellerProfileChange::DESCRIPTION) {
            $mail->line('**Avant :** ' . e(Str::limit((string) ($old['text'] ?? '—'), 600)))
                 ->line('**Après :** ' . e(Str::limit((string) ($new['text'] ?? '—'), 600)));
        } else {
            $label = fn (array $list) => $list ? implode(', ', $list) : '—';
            $profiles = app(StoreProfileService::class);
            $mail->line('**Avant :** ' . $label(array_map(fn ($n) => $profiles->categoryLabel($n), $old['list'] ?? [])))
                 ->line('**Après :** ' . $label(array_map(fn ($n) => $profiles->categoryLabel($n), $new['list'] ?? [])));
        }

        return $mail
            ->line('Le changement est déjà visible sur la boutique.')
            ->action('Ouvrir la fiche vendeur', admin_url() . $this->link());
    }
}
